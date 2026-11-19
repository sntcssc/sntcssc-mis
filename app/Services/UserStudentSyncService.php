<?php

namespace App\Services;

use App\Models\StudentAddress;
use App\Models\StudentProfile;
use App\Models\User;

class UserStudentSyncService
{
    /**
     * Synchronize student profile details whenever a User model is saved.
     */
    public static function syncFromUser(User $user): void
    {
        // 1. Maintain first_name and last_name with name
        if ($user->name && (empty($user->first_name) || empty($user->last_name))) {
            $parts = explode(' ', trim($user->name), 2);
            $user->first_name = $user->first_name ?: ($parts[0] ?? '');
            $user->last_name = $user->last_name ?: ($parts[1] ?? null);
        }

        // 2. If user is a Student or has a student profile
        $isStudent = $user->hasRole('Student') || $user->studentProfile()->exists();

        if ($isStudent) {
            // Ensure student has a lifetime unique ID
            if (empty($user->student_id)) {
                $studentId = StudentIdGeneratorService::generateForStudent($user);
                $user->student_id = $studentId;
                $user->updateQuietly(['student_id' => $studentId]);
            }

            // Sync dob and gender with StudentProfile
            $profile = StudentProfile::where('user_id', $user->id)->first();
            if (! $profile) {
                StudentProfile::create([
                    'user_id' => $user->id,
                    'dob' => $user->dob,
                    'gender' => $user->gender,
                ]);
            } else {
                $updates = [];
                $userDob = $user->dob ? (is_string($user->dob) ? substr($user->dob, 0, 10) : $user->dob->format('Y-m-d')) : null;
                $profileDob = $profile->dob ? (is_string($profile->dob) ? substr($profile->dob, 0, 10) : $profile->dob->format('Y-m-d')) : null;

                if ($userDob && $userDob !== $profileDob) {
                    $updates['dob'] = $user->dob;
                }

                if ($user->gender && $profile->gender !== $user->gender) {
                    $updates['gender'] = $user->gender;
                }

                if (! empty($updates)) {
                    $profile->updateQuietly($updates);
                }
            }
        }
    }

    /**
     * Synchronize User model whenever a StudentProfile model is saved.
     */
    public static function syncFromProfile(StudentProfile $profile): void
    {
        if (! $profile->user_id) {
            return;
        }

        $user = $profile->user;
        if (! $user) {
            return;
        }

        $updates = [];
        $userDob = $user->dob ? (is_string($user->dob) ? substr($user->dob, 0, 10) : $user->dob->format('Y-m-d')) : null;
        $profileDob = $profile->dob ? (is_string($profile->dob) ? substr($profile->dob, 0, 10) : $profile->dob->format('Y-m-d')) : null;

        if ($profileDob && $userDob !== $profileDob) {
            $updates['dob'] = $profile->dob;
        }

        if ($profile->gender && $user->gender !== $profile->gender) {
            $updates['gender'] = $profile->gender;
        }

        if (empty($user->student_id)) {
            $updates['student_id'] = StudentIdGeneratorService::generateForStudent($user);
        }

        if (! empty($updates)) {
            $user->updateQuietly($updates);
        }

        // Ensure the user has the Student role
        if (! $user->hasRole('Student')) {
            $user->assignRole('Student');
        }
    }

    /**
     * Complete unified synchronization for a student across users, student_profiles, and student_addresses.
     */
    public static function syncStudent(User $user, array $userData = [], array $profileData = [], array $addressData = []): User
    {
        // 1. Process and save User
        if (! empty($userData)) {
            if (! empty($userData['first_name']) || ! empty($userData['last_name'])) {
                $userData['name'] = trim(($userData['first_name'] ?? '').' '.($userData['last_name'] ?? ''));
            } elseif (! empty($userData['name']) && empty($userData['first_name'])) {
                $parts = explode(' ', trim($userData['name']), 2);
                $userData['first_name'] = $parts[0] ?? '';
                $userData['last_name'] = $parts[1] ?? '';
            }

            $user->fill($userData);
            $user->save();
        }

        // Ensure Student role
        if (! $user->hasRole('Student')) {
            $user->assignRole('Student');
        }

        // Ensure student ID
        if (empty($user->student_id)) {
            $user->student_id = StudentIdGeneratorService::generateForStudent($user);
            $user->saveQuietly();
        }

        // 2. Sync StudentProfile
        $cleanProfile = array_merge([
            'dob' => $user->dob,
            'gender' => $user->gender,
        ], array_filter($profileData, fn ($val) => ! is_null($val)));

        StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            $cleanProfile
        );

        // 3. Sync StudentAddress (Permanent) if address information given
        $cleanAddress = array_filter($addressData, fn ($val) => ! is_null($val) && $val !== '');
        if (! empty($cleanAddress)) {
            StudentAddress::updateOrCreate(
                ['user_id' => $user->id, 'address_type' => 'Permanent'],
                array_merge([
                    'state' => 'West Bengal',
                ], $cleanAddress)
            );
        }

        return $user;
    }
}
