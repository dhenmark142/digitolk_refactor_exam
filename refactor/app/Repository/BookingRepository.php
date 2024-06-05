<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use DTApi\Repository\NotificationRepository;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;
    protected $notification;

    /**
     * BookingRepository constructor.
     * @param Job $model
     * @param MailerInterface $mailer
     * @param NotificationRepository $notificationRepository
     */
    public function __construct(Job $model, MailerInterface $mailer, NotificationRepository $notificationRepository)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        // Configure logging
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $this->notification = $notificationRepository;
    }

    /**
     * Get the jobs for a user.
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()
                        ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                        ->whereIn('status', ['pending', 'assigned', 'started'])
                        ->orderBy('due', 'asc')
                        ->get();
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $job) {
                if ($job->immediate === 'yes') {
                    $emergencyJobs[] = $job;
                } else {
                    $normalJobs[] = $job;
                }
            }

            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    /**
     * Get job history for a user.
     * @param $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $cuser->jobs()
                    ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);

                $usertype = 'customer';
                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => [],
                    'jobs' => $jobs,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => 0,
                    'pagenum' => 0
                ];
            } elseif ($cuser->is('translator')) {
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
                $totaljobs = $jobs_ids->count();
                $numpages = ceil($totaljobs / 15);

                $usertype = 'translator';

                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => $jobs_ids,
                    'jobs' => $jobs_ids,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => $numpages,
                    'pagenum' => $page
                ];
            }
        }
    }

    /**
     * Store a new job.
     * @param $user
     * @param Request $request
     * @return mixed
     */
    public function store($user, Request $request)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            // Validate the request
            $this->validateRequest($request);

            $request['customer_phone_type'] = isset($request['customer_phone_type']) ? 'yes' : 'no';
            $request['customer_physical_type'] = isset($request['customer_physical_type']) ? 'yes' : 'no';

            // Handle immediate job requests
            if ($request['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinutes($immediatetime);
                $request['due'] = $due_carbon->format('Y-m-d H:i:s');
                $request['immediate'] = 'yes';
                $request['customer_phone_type'] = 'yes';
            } else {
                $due = $request['due_date'] . " " . $request['due_time'];
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $request['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    return ['status' => 'fail', 'message' => "Can't create booking in past"];
                }
            }

            $this->assignJobAttributes($request);

            $request['job_type'] = $this->getJobType($consumer_type);
            $request['b_created_at'] = date('Y-m-d H:i:s');

            if (isset($due)) {
                $request['will_expire_at'] = TeHelper::willExpireAt($due, $request['b_created_at']);
            }

            $request['by_admin'] = $request['by_admin'] ?? 'no';

            $job = $cuser->jobs()->create($request);

            $response = ['status' => 'success', 'id' => $job->id];
            $request['job_for'] = $this->getJobForArray($job);

            $request['customer_town'] = $cuser->userMeta->city;
            $request['customer_type'] = $cuser->userMeta->customer_type;
        } else {
            $response = ['status' => 'fail', 'message' => "Translator can not create booking"];
        }

        return $response;
    }

    /**
     * Store job email.
     * @param Request $request
     * @return mixed
     */
    public function storeJobEmail(Request $request)
    {
        $job = Job::findOrFail($request['user_email_job_id']);
        $job->user_email = $request['user_email'] ?? null;
        $job->reference = $request['reference'] ?? '';
        $user = $job->user()->first();

        $job->address = $request['address'] ?? $user->userMeta->address;
        $job->instructions = $request['instructions'] ?? $user->userMeta->instructions;
        $job->town = $request['town'] ?? $user->userMeta->city;

        $job->save();

        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = ['user' => $user, 'job' => $job];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        Event::fire(new JobWasCreated($job, $request, '*'));

        return ['type' => $request['user_type'], 'job' => $job, 'status' => 'success'];
    }

    /**
     * Convert a job to an array of data.
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job)
    {
        return [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'due_date' => explode(" ", $job->due)[0],
            'due_time' => explode(" ", $job->due)[1],
            'job_for' => $this->getJobForArray($job)
        ];
    }

    /**
     * Get all potential jobs for a user by ID.
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional') {
            $job_type = 'paid';
        } else if ($translator_type == 'rwstranslator') {
            $job_type = 'rws';
        }

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        // Check translator town
        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    /**
     * Check if push notification needs to be delayed.
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        $isNightTime = DateTimeHelper::isNightTime();
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        
        return $isNightTime && $not_get_nighttime == 'yes';
    }

    /**
     * Check if push notification needs to be sent.
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $not_get_notification != 'yes';
    }

    /**
     * Get potential translators for a job.
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translator_type = $this->getTranslatorType($job);
        $translator_level = $this->getTranslatorLevel($job);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $translatorsId);

        return $users;
    }

    /**
     * Update a job with new data.
     * @param $id
     * @param $data
     * @param $cuser
     * @return array
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);
        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->notification->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->notification->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->notification->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * Change the status of a job.
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $statusChanged = false;
        if ($job->status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $job->status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
            }
        }

        return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->notification->sendNotificationTranslator($job, $job_data, '*');   // send Push all suitable translators

            return true;
        }
        
        if ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && !empty($data['admin_comments'])) {
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;

        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['session_time'] == '') return false;
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($user->user->email, $user->user->name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if (empty($data['admin_comments']) && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->notification->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->notification->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if (!empty($data['admin_comments']) && in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (!empty($data['admin_comments']) && $data['status'] != 'timedout') {
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $job->status = $data['status'];
                $job->admin_comments = $data['admin_comments'];
                if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                    $user = $job->user()->first();
                    $email = !empty($job->user_email) ? $job->user_email : $user->email;
                    $dataEmail = [
                        'user' => $user,
                        'job'  => $job
                    ];

                    $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                    $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                    $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                    $dataEmail = [
                        'user' => $user,
                        'job'  => $job
                    ];
                    $this->mailer->send($user->user->email, $user->user->name, $subject, 'emails.job-cancel-translator', $dataEmail);
                }

                $job->save();
                return true;
            }
        }

        return false;
    }

    /**
     * Change the translator for a job.
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * Change the due date of a job.
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * Accept a job.
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }
            $jobs = $this->getPotentialJobs($cuser);
            return ['status' => 'success', 'list' => json_encode(['jobs' => $jobs, 'job' => $job], true)];
        } else {
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'];
        }
    }

    /**
     * Accept a job by ID.
     * @param $job_id
     * @param $cuser
     * @return array
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = ['notification_type' => 'job_accepted'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                ];
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->notification->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                return ['status' => 'success', 'list' => ['job' => $job], 'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due];
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                return ['status' => 'fail', 'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'];
            }
        } else {
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'];
        }
    }

    /**
     * Cancel a job via AJAX.
     * @param $data
     * @param $user
     * @return array
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $data = ['notification_type' => 'job_cancelled'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->notification->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = ['notification_type' => 'job_cancelled'];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = [$customer];
                        $this->notification->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->notification->sendNotificationTranslator($job, $data, $translator->id);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    /**
     * Get potential jobs for a user.
     * @param $cuser
     * @return mixed
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = $this->getJobTypeForTranslator($cuser_meta->translator_type);

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $cuser_meta->gender, $cuser_meta->translator_level);

        // Check if translator can accept job
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    /**
     * End a job.
     * @param $post_data
     * @return array
     */
    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobid);

        if ($job->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff[1] . ':' . $diff[2];
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    /**
     * Handle when the customer does not call.
     * @param $post_data
     * @return array
     */
    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff[1] . ':' . $diff[2];
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    /**
     * Get all jobs with filtering options.
     * @param Request $request
     * @param null $limit
     * @return mixed
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['id']) && !empty($requestdata['id'])) {
                if (is_array($requestdata['id']))
                    $allJobs->whereIn('id', $requestdata['id']);
                else
                    $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if (isset($requestdata['lang']) && !empty($requestdata['lang'])) {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && !empty($requestdata['status'])) {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['expired_at']) && !empty($requestdata['expired_at'])) {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }
            if (isset($requestdata['will_expire_at']) && !empty($requestdata['will_expire_at'])) {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }
            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && !empty($requestdata['customer_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && !empty($requestdata['from'])) {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && !empty($requestdata['to'])) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && !empty($requestdata['from'])) {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && !empty($requestdata['to'])) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestdata['job_type']) && !empty($requestdata['job_type'])) {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);
                if (isset($requestdata['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestdata['salary']) && $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                $allJobs = $allJobs->count();
                return ['count' => $allJobs];
            }

            if (isset($requestdata['consumer_type']) && !empty($requestdata['consumer_type'])) {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestdata['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        } else {

            $allJobs = Job::query();

            if (isset($requestdata['id']) && !empty($requestdata['id'])) {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['lang']) && !empty($requestdata['lang'])) {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && !empty($requestdata['status'])) {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['job_type']) && !empty($requestdata['job_type'])) {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }
            if (isset($requestdata['customer_email']) && !empty($requestdata['customer_email'])) {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && !empty($requestdata['from'])) {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && !empty($requestdata['to'])) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && !empty($requestdata['from'])) {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && !empty($requestdata['to'])) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }

    /**
     * Reopen a job.
     * @param $request
     * @return array
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = [];
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRows)) {
            $this->notification->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hours and minutes.
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    /**
     * Get the job type for a translator.
     * @param string $translator_type
     * @return string
     */
    private function getJobTypeForTranslator($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
                return 'unpaid';
            default:
                return 'unpaid';
        }
    }

    /**
     * Get the translator type for a job.
     * @param Job $job
     * @return string
     */
    private function getTranslatorType(Job $job)
    {
        switch ($job->job_type) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return 'volunteer';
        }
    }

    /**
     * Get the translator level for a job.
     * @param Job $job
     * @return array
     */
    private function getTranslatorLevel(Job $job)
    {
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level = ['Certified with specialisation in law'];
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level = ['Certified with specialisation in health care'];
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level = ['Layman', 'Read Translation courses'];
            } elseif ($job->certified == null) {
                $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
            }
        }
        return $translator_level;
    }
}
