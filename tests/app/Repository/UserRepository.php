<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Models\UserLanguages;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\FirePHPHandler;

/**
 * Class UserRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{
    protected $model;
    protected $logger;

    /**
     * UserRepository constructor.
     * @param User $model
     */
    function __construct(User $model)
    {
        parent::__construct($model);
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Create or update a user.
     * @param int|null $id
     * @param array $request
     * @return User|bool
     */
    public function createOrUpdate($id = null, $request)
    {
        $model = is_null($id) ? new User : User::findOrFail($id);
        $model->user_type = $request['role'];
        $model->name = $request['name'];
        $model->company_id = $request['company_id'] ?? 0;
        $model->department_id = $request['department_id'] ?? 0;
        $model->email = $request['email'];
        $model->dob_or_orgid = $request['dob_or_orgid'];
        $model->phone = $request['phone'];
        $model->mobile = $request['mobile'];

        if (!$id || ($id && $request['password'])) {
            $model->password = bcrypt($request['password']);
        }
        $model->detachAllRoles();
        $model->save();
        $model->attachRole($request['role']);

        if ($request['role'] == env('CUSTOMER_ROLE_ID')) {
            $this->handleCustomerMeta($model, $request);
        } elseif ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
            $this->handleTranslatorMeta($model, $request);
        }

        $this->handleTowns($model, $request);
        $this->handleStatus($model, $request['status']);

        return $model ?: false;
    }

    /**
     * Handle customer meta data.
     * @param User $model
     * @param array $request
     */
    private function handleCustomerMeta($model, $request)
    {
        if ($request['consumer_type'] == 'paid' && $request['company_id'] == '') {
            $type = Type::where('code', 'paid')->first();
            $company = Company::create([
                'name' => $request['name'],
                'type_id' => $type->id,
                'additional_info' => 'Created automatically for user ' . $model->id
            ]);
            $department = Department::create([
                'name' => $request['name'],
                'company_id' => $company->id,
                'additional_info' => 'Created automatically for user ' . $model->id
            ]);
            $model->company_id = $company->id;
            $model->department_id = $department->id;
            $model->save();
        }

        $user_meta = UserMeta::firstOrCreate(['user_id' => $model->id]);
        $user_meta->fill([
            'consumer_type' => $request['consumer_type'],
            'customer_type' => $request['customer_type'],
            'username' => $request['username'],
            'post_code' => $request['post_code'],
            'address' => $request['address'],
            'city' => $request['city'],
            'town' => $request['town'],
            'country' => $request['country'],
            'reference' => isset($request['reference']) && $request['reference'] == 'yes' ? '1' : '0',
            'additional_info' => $request['additional_info'],
            'cost_place' => $request['cost_place'] ?? '',
            'fee' => $request['fee'] ?? '',
            'time_to_charge' => $request['time_to_charge'] ?? '',
            'time_to_pay' => $request['time_to_pay'] ?? '',
            'charge_ob' => $request['charge_ob'] ?? '',
            'customer_id' => $request['customer_id'] ?? '',
            'charge_km' => $request['charge_km'] ?? '',
            'maximum_km' => $request['maximum_km'] ?? ''
        ]);
        $user_meta->save();

        $this->handleBlacklist($model, $request['translator_ex']);
    }

    /**
     * Handle translator meta data.
     * @param User $model
     * @param array $request
     */
    private function handleTranslatorMeta($model, $request)
    {
        $user_meta = UserMeta::firstOrCreate(['user_id' => $model->id]);
        $user_meta->fill([
            'translator_type' => $request['translator_type'],
            'worked_for' => $request['worked_for'],
            'organization_number' => $request['worked_for'] == 'yes' ? $request['organization_number'] : null,
            'gender' => $request['gender'],
            'translator_level' => $request['translator_level'],
            'additional_info' => $request['additional_info'],
            'post_code' => $request['post_code'],
            'address' => $request['address'],
            'address_2' => $request['address_2'],
            'town' => $request['town']
        ]);
        $user_meta->save();

        $this->handleLanguages($model, $request['user_language']);
    }

    /**
     * Handle user languages.
     * @param User $model
     * @param array $languages
     */
    private function handleLanguages($model, $languages)
    {
        $langidUpdated = [];
        if ($languages) {
            foreach ($languages as $langId) {
                $userLang = new UserLanguages();
                if ($userLang::langExist($model->id, $langId) == 0) {
                    $userLang->user_id = $model->id;
                    $userLang->lang_id = $langId;
                    $userLang->save();
                }
                $langidUpdated[] = $langId;
            }
            UserLanguages::deleteLang($model->id, $langidUpdated);
        }
    }

    /**
     * Handle blacklist for user.
     * @param User $model
     * @param array|null $translators
     */
    private function handleBlacklist($model, $translators)
    {
        $blacklistUpdated = [];
        $userBlacklist = UsersBlacklist::where('user_id', $model->id)->get();
        $userTranslId = collect($userBlacklist)->pluck('translator_id')->all();

        if ($translators) {
            $diff = array_intersect($userTranslId, $translators);
            foreach ($translators as $translatorId) {
                if (UsersBlacklist::translatorExist($model->id, $translatorId) == 0) {
                    UsersBlacklist::create([
                        'user_id' => $model->id,
                        'translator_id' => $translatorId
                    ]);
                }
                $blacklistUpdated[] = $translatorId;
            }
            UsersBlacklist::deleteFromBlacklist($model->id, $blacklistUpdated);
        } else {
            UsersBlacklist::where('user_id', $model->id)->delete();
        }
    }

    /**
     * Handle user towns.
     * @param User $model
     * @param array $request
     */
    private function handleTowns($model, $request)
    {
        if ($request['new_towns']) {
            $towns = Town::create(['townname' => $request['new_towns']]);
        }

        $townidUpdated = [];
        if ($request['user_towns_projects']) {
            DB::table('user_towns')->where('user_id', '=', $model->id)->delete();
            foreach ($request['user_towns_projects'] as $townId) {
                if (UserTowns::townExist($model->id, $townId) == 0) {
                    UserTowns::create([
                        'user_id' => $model->id,
                        'town_id' => $townId
                    ]);
                }
                $townidUpdated[] = $townId;
            }
        }
    }

    /**
     * Handle user status.
     * @param User $model
     * @param string $status
     */
    private function handleStatus($model, $status)
    {
        if ($status == '1' && $model->status != '1') {
            $this->enable($model->id);
        } elseif ($status != '1' && $model->status != '0') {
            $this->disable($model->id);
        }
    }

    /**
     * Enable a user.
     * @param int $id
     */
    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = 1;
        $user->save();
    }

    /**
     * Disable a user.
     * @param int $id
     */
    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = 0;
        $user->save();
    }

    /**
     * Get all translators.
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }
}
