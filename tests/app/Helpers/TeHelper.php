<?php

namespace DTApi\Helpers;

use Carbon\Carbon;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use Illuminate\Support\Facades\Log;

class TeHelper
{
    /**
     * Fetch the language name from the job ID.
     * @param int $id
     * @return string
     */
    public static function fetchLanguageFromJobId($id)
    {
        $language = Language::findOrFail($id);
        return $language->language;
    }

    /**
     * Get user meta data.
     * @param int $user_id
     * @param string|bool $key
     * @return mixed
     */
    public static function getUsermeta($user_id, $key = false)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();

        if (!$key) {
            return $userMeta->usermeta()->get()->all();
        }

        return $userMeta->$key ?? '';
    }

    /**
     * Convert job IDs to job objects.
     * @param array $jobs_ids
     * @return array
     */
    public static function convertJobIdsInObjs($jobs_ids)
    {
        return array_map(function($job_obj) {
            return Job::findOrFail($job_obj->id);
        }, $jobs_ids);
    }

    /**
     * Calculate the expiration time for a job.
     * @param string $due_time
     * @param string $created_at
     * @return string
     */
    public static function willExpireAt($due_time, $created_at)
    {
        $due_time = Carbon::parse($due_time);
        $created_at = Carbon::parse($created_at);

        $difference = $due_time->diffInHours($created_at);

        if ($difference <= 90) {
            $time = $due_time;
        } elseif ($difference <= 24) {
            $time = $created_at->addMinutes(90);
        } elseif ($difference > 24 && $difference <= 72) {
            $time = $created_at->addHours(16);
        } else {
            $time = $due_time->subHours(48);
        }

        return $time->format('Y-m-d H:i:s');
    }
}
