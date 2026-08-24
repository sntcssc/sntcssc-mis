<?php

namespace App\Services;

use App\Exports\TableExport;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UserExportImportService
{
    /**
     * Export column headings for spreadsheet exports.
     *
     * @return array<int, string>
     */
    public static function exportHeadings(): array
    {
        return [
            'URN',
            'Name',
            'First Name',
            'Last Name',
            'Email',
            'Phone',
            'WhatsApp No',
            'Gender',
            'Date of Birth',
            '10th Roll No',
            'ID Type',
            'ID Number',
            'Designation',
            'Primary Role',
            'Status',
            'Last Login At',
            'Last Login IP',
            'Created At',
        ];
    }

    /**
     * Map user model to flat row array for spreadsheet export.
     *
     * @return array<int, mixed>
     */
    public static function mapUserRow(User $user): array
    {
        return [
            $user->urn ?? '—',
            $user->name,
            $user->first_name ?? '',
            $user->last_name ?? '',
            $user->email,
            $user->phone ?? '',
            $user->whatsapp_no ?? '',
            ucfirst((string) $user->gender),
            $user->dob?->format('Y-m-d') ?? '',
            $user->tenth_roll ?? '',
            strtoupper((string) $user->id_type),
            $user->id_number ?? '',
            $user->designation ?? '',
            $user->roles->pluck('name')->first() ?? 'Staff',
            ucfirst((string) $user->status),
            $user->last_login_at?->format('Y-m-d H:i:s') ?? 'Never',
            $user->last_login_ip ?? '',
            $user->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    /**
     * Export users query to streamed CSV.
     */
    public static function exportCsv(Builder $query, string $filename = 'users-export'): StreamedResponse
    {
        $filename = Str::slug($filename).'-'.now()->format('Ymd-His').'.csv';
        $headings = static::exportHeadings();

        return response()->streamDownload(function () use ($query, $headings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headings);

            $query->with('roles')->chunk(200, function ($users) use ($handle) {
                foreach ($users as $user) {
                    fputcsv($handle, static::mapUserRow($user));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export users query to Excel (.xlsx).
     */
    public static function exportExcel(Builder $query, string $filename = 'users-export'): BinaryFileResponse
    {
        $filename = Str::slug($filename).'-'.now()->format('Ymd-His').'.xlsx';

        $export = new TableExport(
            query: $query->with('roles'),
            headings: static::exportHeadings(),
            mapper: fn (User $user) => static::mapUserRow($user)
        );

        return Excel::download($export, $filename);
    }

    /**
     * Export users query to printable PDF.
     */
    public static function exportPdf(Builder $query, string $title = 'System Users Directory'): Response
    {
        $users = $query->with('roles')->latest('id')->limit(500)->get();
        $filename = Str::slug($title).'-'.now()->format('Ymd-His').'.pdf';

        $pdf = Pdf::loadView('exports.users-pdf', [
            'users' => $users,
            'title' => $title,
            'generatedAt' => now()->format('d M Y, h:i A'),
            'totalCount' => $users->count(),
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * Generate downloadable sample CSV template for batch import.
     */
    public static function downloadImportTemplate(): StreamedResponse
    {
        $filename = 'users-import-template.csv';
        $headings = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'whatsapp_no',
            'gender',
            'dob',
            'tenth_roll',
            'id_type',
            'id_number',
            'designation',
            'role',
            'status',
            'password',
        ];

        $sampleRow = [
            'Ananya',
            'Roy',
            'ananya.roy@example.com',
            '+919876543210',
            '+919876543210',
            'female',
            '1998-05-15',
            'ROLL2014-9982',
            'aadhaar',
            '1234-5678-9012',
            'Admissions Coordinator',
            'Admissions Officer',
            'active',
            'Password@123',
        ];

        return response()->streamDownload(function () use ($headings, $sampleRow) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headings);
            fputcsv($handle, $sampleRow);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Import users from uploaded CSV or Excel file with transaction and validation.
     *
     * @return array{success: bool, imported_count: int, skipped_count: int, errors: array<string>}
     */
    public static function importUsers(UploadedFile|string $file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $rows = [];

        if (str_ends_with(strtolower($filePath), '.csv') || ($file instanceof UploadedFile && $file->getClientOriginalExtension() === 'csv')) {
            $rows = static::parseCsvFile($filePath);
        } else {
            // Excel format parse using Excel Facade
            $excelData = Excel::toArray([], $filePath);
            if (! empty($excelData[0])) {
                $rawRows = $excelData[0];
                $headers = array_map(fn ($h) => Str::snake(trim((string) $h)), array_shift($rawRows));
                foreach ($rawRows as $row) {
                    if (empty(array_filter($row))) {
                        continue;
                    }
                    $rowMap = [];
                    foreach ($headers as $index => $key) {
                        $rowMap[$key] = $row[$index] ?? null;
                    }
                    $rows[] = $rowMap;
                }
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2; // account for header line

                $firstName = trim((string) ($row['first_name'] ?? ''));
                $lastName = trim((string) ($row['last_name'] ?? ''));
                $name = trim((string) ($row['name'] ?? ($firstName.' '.$lastName)));
                $email = strtolower(trim((string) ($row['email'] ?? '')));

                if (empty($email)) {
                    $skipped++;
                    $errors[] = "Row #{$rowNum}: Email address is required.";

                    continue;
                }

                $validator = Validator::make([
                    'email' => $email,
                    'first_name' => $firstName,
                    'phone' => $row['phone'] ?? null,
                ], [
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'first_name' => ['nullable', 'string', 'max:100'],
                    'phone' => ['nullable', 'string', 'max:30'],
                ]);

                if ($validator->fails()) {
                    $skipped++;
                    $errors[] = "Row #{$rowNum} ({$email}): ".$validator->errors()->first();

                    continue;
                }

                $dob = null;
                if (! empty($row['dob'])) {
                    try {
                        $dob = Carbon::parse($row['dob'])->format('Y-m-d');
                    } catch (Throwable) {
                        $dob = null;
                    }
                }

                $user = User::create([
                    'name' => $name ?: 'User',
                    'first_name' => $firstName ?: null,
                    'last_name' => $lastName ?: null,
                    'email' => $email,
                    'phone' => ! empty($row['phone']) ? trim((string) $row['phone']) : null,
                    'whatsapp_no' => ! empty($row['whatsapp_no']) ? trim((string) $row['whatsapp_no']) : null,
                    'dob' => $dob,
                    'gender' => ! empty($row['gender']) ? strtolower(trim((string) $row['gender'])) : null,
                    'tenth_roll' => ! empty($row['tenth_roll']) ? trim((string) $row['tenth_roll']) : null,
                    'id_type' => ! empty($row['id_type']) ? strtolower(trim((string) $row['id_type'])) : null,
                    'id_number' => ! empty($row['id_number']) ? trim((string) $row['id_number']) : null,
                    'designation' => ! empty($row['designation']) ? trim((string) $row['designation']) : null,
                    'status' => ! empty($row['status']) ? strtolower(trim((string) $row['status'])) : 'active',
                    'password' => Hash::make($row['password'] ?? Str::random(12)),
                ]);

                // Assign role
                $roleName = trim((string) ($row['role'] ?? 'Staff'));
                if ($roleName && Role::where('name', $roleName)->exists()) {
                    $user->assignRole($roleName);
                } else {
                    $user->assignRole('Staff');
                }

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'users_batch_imported',
                description: "Imported {$imported} users from spreadsheet ({$skipped} skipped).",
                newValues: ['imported' => $imported, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Users import batch failed: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'imported_count' => 0,
                'skipped_count' => count($rows),
                'errors' => ['Import failed due to server error: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Parse raw CSV file content into associative arrays.
     *
     * @return array<int, array<string, string|null>>
     */
    protected static function parseCsvFile(string $filePath): array
    {
        $rows = [];
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return $rows;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return $rows;
        }

        $rawHeaders = fgetcsv($handle);
        if (! $rawHeaders) {
            fclose($handle);

            return $rows;
        }

        $headers = array_map(fn ($h) => Str::snake(trim((string) $h)), $rawHeaders);

        while (($data = fgetcsv($handle)) !== false) {
            if (empty(array_filter($data))) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $key) {
                $row[$key] = isset($data[$index]) ? trim((string) $data[$index]) : null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
