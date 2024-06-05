<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use DTApi\Repository\NotificationRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     * @var NotificationRepository
     */
    protected $repository;
    protected $notification;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     * @param NotificationRepository $notificationRepository
     */
    public function __construct(BookingRepository $bookingRepository, NotificationRepository $notificationRepository)
    {
        $this->repository = $bookingRepository;
        $this->notification = $notificationRepository;
    }

    /**
     * Display a listing of the user's jobs or all jobs for admins.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user_id = $request->get('user_id');
        if ($user_id) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Display the specified job.
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        return response($job);
    }

    /**
     * Store a newly created job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $response = $this->repository->store($request->__authenticatedUser, $request->all());
        return response($response);
    }

    /**
     * Update the specified job.
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function update($id, Request $request)
    {
        $response = $this->repository->updateJob($id, array_except($request->all(), ['_token', 'submit']), $request->__authenticatedUser);
        return response($response);
    }

    /**
     * Store an immediate job email.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function immediateJobEmail(Request $request)
    {
        $response = $this->repository->storeJobEmail($request->all());
        return response($response);
    }

    /**
     * Display the user's job history.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');
        if ($user_id) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }
        return response(null, 400);
    }

    /**
     * Accept a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function acceptJob(Request $request)
    {
        $response = $this->repository->acceptJob($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    /**
     * Accept a job by ID.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $response = $this->repository->acceptJobWithId($data, $request->__authenticatedUser);
        return response($response);
    }

    /**
     * Cancel a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function cancelJob(Request $request)
    {
        $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    /**
     * End a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function endJob(Request $request)
    {
        $response = $this->repository->endJob($request->all());
        return response($response);
    }

    /**
     * Handle customer not calling for a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function customerNotCall(Request $request)
    {
        $response = $this->repository->customerNotCall($request->all());
        return response($response);
    }

    /**
     * Get potential jobs for a user.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);
        return response($response);
    }

    /**
     * Update distance feed for a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $distance = $data['distance'] ?? "";
        $time = $data['time'] ?? "";
        $jobid = $data['jobid'] ?? "";
        $session = $data['session_time'] ?? "";
        $manually_handled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $by_admin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? "";

        $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';

        if ($flagged == 'yes' && empty($admincomment)) {
            return response("Please, add comment", 400);
        }

        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manually_handled,
                'by_admin' => $by_admin
            ]);
        }

        return response('Record updated!');
    }

    /**
     * Reopen a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function reopen(Request $request)
    {
        $response = $this->repository->reopen($request->all());
        return response($response);
    }

    /**
     * Resend notifications for a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->notification->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Resend SMS notifications for a job.
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->notification->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }
}
