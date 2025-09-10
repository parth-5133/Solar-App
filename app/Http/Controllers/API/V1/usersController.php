<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Sequence;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\Notification;
use App\Models\EmployeeInfo;
use App\Helpers\ApiResponse;
use App\Helpers\AccessLevel;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\StoreUpdateUserRequest;
use App\Helpers\JWTUtils;
use App\Helpers\GetCompanyId;
use App\Constants\ResMessages;
use Illuminate\Http\Request;
use App\Helpers\FinancialYearService;

class UsersController extends Controller
{
    public function index(Request $request)
    {
        $role_id = $request->query('role_id');
        $companiesId = GetCompanyId::GetCompanyId();

        $currentUser = JWTUtils::getCurrentUserByUuid();
        $userId = $currentUser->id;

        $currentAccessLevel = AccessLevel::getAccessLevel();

        $usersQuery = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->leftJoin('users as updater', 'users.updated_by', '=', 'updater.id')
            ->select(
                'users.id',
                DB::raw("CONCAT(users.first_name, ' ', IFNULL(users.middle_name, ''), ' ', users.last_name) as name"),
                'users.email',
                'users.is_active',
                'roles.name as role_name',
                'roles.code as role_code',
                DB::raw("CONCAT(updater.first_name, ' ', updater.last_name) as updated_name"),
                DB::raw("DATE_FORMAT(users.updated_at, '%d/%m/%Y') as updated_at_formatted")
            )
            ->where('users.id', '!=', $userId)
            ->where('roles.access_level', '<=', $currentAccessLevel->access_level)
            ->whereNull('users.deleted_at');

        if ($companiesId) {
            $usersQuery->where('users.company_id', $companiesId);
        }

        if (!is_null($role_id)) {
            $usersQuery->where('users.role_id', $role_id);
        }

        $users = $usersQuery->orderByDesc('id')->get();

        return ApiResponse::success($users, ResMessages::RETRIEVED_SUCCESS);
    }
    public function store(StoreUpdateUserRequest $request)
    {
        DB::beginTransaction();

        try {
            $currentUser = JWTUtils::getCurrentUserByUuid();
            $financialYears = FinancialYearService::getCurrentFinancialYear();
            $CompanyId = GetCompanyId::GetCompanyId();
            $userData = $request->all();

            if ($CompanyId == null) {
                return ApiResponse::error(ResMessages::NOT_FOUND, 404);
            }
            $roleId = $request->role_id;

            $role = Role::find($roleId);

            if (!$role) {
                return ApiResponse::error(ResMessages::NOT_FOUND, 404);
            }

            if ($role->code == $this->employeeRoleCode) {
                $sequenceNo = Sequence::where('type', 'EmployeeID')->value('sequenceNo');
                $newSequenceNo = ($sequenceNo !== null) ? $sequenceNo + 1 : 1;
                $sequenceId = str_pad($newSequenceNo, 4, '0', STR_PAD_LEFT);
            }

            if ($role->code == $this->clientRoleCode) {
                $sequenceNo = Sequence::where('type', 'ClientID')->value('sequenceNo');
                $newSequenceNo = ($sequenceNo !== null) ? $sequenceNo + 1 : 1;
                $sequenceId = str_pad($newSequenceNo, 4, '0', STR_PAD_LEFT);
            }

            if ($role->code == $this->AdminRoleCode) {
                $sequenceId = null;
            }

            // Prepare user data with additional fields
            $userData['employee_id'] = $sequenceId;
            $userData['created_by'] = $currentUser->id;
            $userData['created_at'] = now();
            $userData['updated_at'] = null;
            $userData['ip_address'] = $request->ip();
            $userData['last_logged_in_at'] = now();
            $userData['company_id'] = $CompanyId;

            if ($request->has('role_id')) {
                $userData['role_id'] = $request->role_id;
            }

            $user = User::create($userData);

            if ($role->code == $this->employeeRoleCode) {
                Sequence::where('type', 'EmployeeID')->update(['sequenceNo' => $newSequenceNo]);
            }

            if ($role->code == $this->clientRoleCode) {
                Sequence::where('type', 'ClientID')->update(['sequenceNo' => $newSequenceNo]);
            }

            $leaveTypes = LeaveType::all();
            if ($leaveTypes->isNotEmpty()) {
                $currentYear = now()->year;

                foreach ($leaveTypes as $leaveType) {
                    LeaveBalance::create([
                        'employee_id' => $user->id,
                        'company_id' => $user->company_id,
                        'financialYear_id' => $financialYears->id,
                        'leave_type_id' => $leaveType->id,
                        'year' => $currentYear,
                        'balance' => $leaveType->max_days,
                        'carry_forwarded' => 0,
                    ]);
                }
            }

            DB::commit();
            return ApiResponse::success($user, ResMessages::CREATED_SUCCESS);
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
    public function view(Request $request)
    {
        $userId = $request->userId;

        $user = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id') // Directly join roles using role_id in users table
            ->select(
                'users.*',
                'roles.id as role_id',
                'roles.name as role_name' // Fetch role name from roles table
            )
            ->where('users.id', $userId)
            ->first();

        if ($user) {
            return ApiResponse::success($user, ResMessages::RETRIEVED_SUCCESS);
        } else {
            return ApiResponse::error(ResMessages::NOT_FOUND);
        }
    }
    public function update(StoreUpdateUserRequest $request)
    {
        $userId = $request->userId;

        $user = User::find($userId);

        if (!$user) {
            return ApiResponse::error(ResMessages::NOT_FOUND);
        }

        $currentUser = JWTUtils::getCurrentUserByUuid();

        // Update user details
        $user->fill($request->validated());
        $user->updated_by = $currentUser->id;
        $user->updated_at = now();

        // If password is being updated, hash it
        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        // Update the role_id directly in the users table
        if ($request->has('role_id')) {
            $user->role_id = $request->role_id;
        }

        // Save the updated user
        $user->save();

        return ApiResponse::success($user, ResMessages::UPDATED_SUCCESS);
    }
    public function delete($id)
    {
        $user = User::find($id);

        if ($user) {
            $user->delete();
            return ApiResponse::success($user, ResMessages::DELETED_SUCCESS);
        } else {
            return ApiResponse::error($user, ResMessages::NOT_FOUND);
        }
    }
    public function showNotifications()
    {
        $currentUser = JWTUtils::getCurrentUserByUuid();
        $companyId = GetCompanyId::GetCompanyId();

        if ($companyId == null) {
            return ApiResponse::error(ResMessages::COMPANY_NOT_FOUND, 404);
        }
        $notifications = Notification::where('deleted_at', null)
            ->where('company_id', $companyId)
            ->where('user_id', $currentUser->id)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($notifications, ResMessages::RETRIEVED_SUCCESS);
    }
    public function markAllAsRead(Request $request)
    {
        $id = $request->input('id');

        if (!empty($id) && $id != 0) {
            $notification = Notification::find($id);

            if ($notification) {
                $notification->read = true;
                $notification->save();
                return ApiResponse::success($notification, ResMessages::UPDATED_SUCCESS, 1);
            }

            return ApiResponse::error(ResMessages::NOT_FOUND);
        }

        $currentUser = JWTUtils::getCurrentUserByUuid();

        $notifications = Notification::where('user_id', $currentUser->id)
            ->where('read', false)
            ->update(['read' => true]);

        return ApiResponse::success($notifications, ResMessages::RETRIEVED_SUCCESS);
    }
    public function deleteNotification($id)
    {
        if ($id == "0") {
            $currentUser = JWTUtils::getCurrentUserByUuid();
            $userId = $currentUser->id;

            $deleted = Notification::where('user_id', $userId)->delete();

            if ($deleted) {
                return ApiResponse::success([], ResMessages::DELETED_SUCCESS, 11);
            } else {
                return ApiResponse::error(ResMessages::NOT_FOUND);
            }
        }

        $notification = Notification::find($id);

        if ($notification) {
            $notification->delete();
            return ApiResponse::success($notification, ResMessages::DELETED_SUCCESS, 10);
        } else {
            return ApiResponse::error(ResMessages::NOT_FOUND);
        }
    }

    public function getTodaysBirthday()
    {
        $today = now();
        $users = EmployeeInfo::whereMonth('date_of_birth', $today->month)
            ->whereDay('date_of_birth', $today->day)
            ->join('users', 'employee_infos.user_id', '=', 'users.id')
            ->join('employee_jobs', 'employee_infos.user_id', '=', 'employee_jobs.user_id')
            ->join('departments', 'employee_jobs.department', '=', 'departments.id')
            ->select(
                'departments.name as department',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as name"),
                'employee_infos.profile_image',
                'employee_infos.date_of_birth'
            )
            ->get();

        return ApiResponse::success($users, ResMessages::RETRIEVED_SUCCESS);
    }

    public function getEmployeesList($isEmployee)
    {
        $companiesId = GetCompanyId::GetCompanyId();

        $query = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->leftJoin('employee_infos', 'users.id', '=', 'employee_infos.user_id')
            ->leftJoin('employee_jobs', 'users.id', '=', 'employee_jobs.user_id')
            ->leftJoin('departments', 'employee_jobs.department', '=', 'departments.id')
            ->whereNull('users.deleted_at')
            ->where('roles.code',  $this->employeeRoleCode)
            ->where('users.company_id', $companiesId)
            ->select(
                'departments.name as department',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as name"),
                'employee_infos.profile_image',
                'employee_jobs.date_of_joining'
            );

        if ($isEmployee) {
            $currentUser = JWTUtils::getCurrentUserByUuid();
            $query->where('users.id', '!=', $currentUser->id);
        }

        $users = $query->get();

        return ApiResponse::success($users, ResMessages::RETRIEVED_SUCCESS);
    }
}
