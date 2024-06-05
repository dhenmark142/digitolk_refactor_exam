<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class NotificationRepository
 * @package DTApi\Repository
 */
class NotificationRepository {
    /**
     * @var BookingRepository
     */
    protected $booking;
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * NotificationRepository constructor.
     * @param Job $model
     * @param MailerInterface $mailer
     * @param BookingRepository $bookingRepository
     */
    public function __construct(Job $model, MailerInterface $mailer, BookingRepository $bookingRepository)
    {
        $this->model = $model;
        $this->mailer = $mailer;
        $this->booking = $bookingRepository;

        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Check if push notification needs to be sent
     * @param int $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $not_get_notification !== 'yes';
    }

    /**
     * Send notifications to translators
     * @param Job $job
     * @param array $data
     * @param int $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = []; // Suitable translators (no need to delay push)
        $delpay_translator_array = []; // Suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) {
                if (!$this->booking->isNeedToSendPush($oneUser->id)) continue;

                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;

                $jobs = $this->booking->getPotentialJobIdsWithUserId($oneUser->id);
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->booking->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msg_contents = $data['immediate'] == 'no' ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msg_text = [
            "en" => $msg_contents
        ];

        $this->logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true);
    }

    /**
     * Send SMS notifications to translators and return count of translators
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->booking->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->booking->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // Determine message type (phone or physical)
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            $message = $physicalJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            $message = $phoneJobMessageTemplate;
        } else {
            $message = $phoneJobMessageTemplate;
        }

        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Create user tags string from users array for OneSignal notifications
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';

        return $user_tags;
    }

    /**
     * Send OneSignal push notifications with user tags
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param array $msg_text
     * @param bool $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $android_sound,
            'ios_sound' => $ios_sound
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Temporary method to send session start remind notification
     * @param User $user
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param string $duration
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = [
            'notification_type' => 'session_start_remind'
        ];
        $due_explode = explode(' ', $due);
        $msg_text = $job->customer_physical_type == 'yes' ? [
            "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
        ] : [
            "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->booking->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Send notification when a translator is changed
     * @param Job $job
     * @param Translator $current_translator
     * @param Translator $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $this->mailer->send($current_translator->user->email, $current_translator->user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $this->mailer->send($new_translator->user->email, $new_translator->user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * Send notification when a job date is changed
     * @param Job $job
     * @param string $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Send notification when a job language is changed
     * @param Job $job
     * @param string $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Send notification when admin cancels a job
     * @param int $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
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
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
            'due_date' => explode(" ", $job->due)[0],
            'due_time' => explode(" ", $job->due)[1],
            'job_for' => []
        ];

        if ($job->gender == 'male') {
            $data['job_for'][] = 'Man';
        } elseif ($job->gender == 'female') {
            $data['job_for'][] = 'Kvinna';
        }

        if ($job->certified == 'both') {
            $data['job_for'][] = 'normal';
            $data['job_for'][] = 'certified';
        } elseif ($job->certified == 'yes') {
            $data['job_for'][] = 'certified';
        } else {
            $data['job_for'][] = $job->certified;
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }
}
