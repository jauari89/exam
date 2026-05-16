<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage_series' => 'Manage exam series',
            'manage_exams' => 'Manage exams and sessions',
            'manage_packages' => 'Import and publish exam packages',
            'manage_question_bank' => 'Manage question bank authoring and package builds',
            'manage_candidates' => 'Manage candidates',
            'generate_tokens' => 'Generate candidate tokens',
            'proctor_sessions' => 'Proctor live sessions',
            'manage_incidents' => 'Manage incidents',
            'mark_submissions' => 'Mark manual answers',
            'moderate_marks' => 'Moderate and finalize marks',
            'view_reports' => 'View reports',
            'export_evidence' => 'Export evidence bundles',
            'view_audit' => 'View audit log',
        ];

        $permissionModels = collect($permissions)->mapWithKeys(
            fn (string $label, string $name) => [$name => Permission::query()->firstOrCreate(['name' => $name], ['label' => $label])]
        );

        $roles = [
            'admin' => array_keys($permissions),
            'proctor' => ['proctor_sessions', 'manage_incidents', 'generate_tokens'],
            'marker' => ['mark_submissions'],
            'reviewer' => ['moderate_marks', 'view_reports'],
            'auditor' => ['view_reports', 'view_audit', 'export_evidence'],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::query()->firstOrCreate(['name' => $name], ['label' => str($name)->headline()->toString()]);
            $role->permissions()->sync($permissionModels->only($rolePermissions)->pluck('id')->all());
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
            ],
        );

        $admin->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->value('id')]);
    }
}
