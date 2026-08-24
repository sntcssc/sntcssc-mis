<?php

namespace App\Services;

use App\Exports\TableExport;
use App\Models\Permission;
use App\Models\Role;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RolePermissionExportService
{
    /**
     * Export roles and their granted permissions to CSV.
     */
    public static function exportRolesCsv(): StreamedResponse
    {
        $filename = 'roles-permissions-'.now()->format('Ymd-His').'.csv';
        $roles = Role::with('permissions')->orderBy('name')->get();

        return response()->streamDownload(function () use ($roles) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Role Name', 'Type', 'Color', 'Users Count', 'Permissions Count', 'Description', 'Permissions List']);

            foreach ($roles as $role) {
                fputcsv($handle, [
                    $role->name,
                    $role->is_system ? 'System Protected' : 'Custom Role',
                    $role->color,
                    $role->users()->count(),
                    $role->permissions->count(),
                    $role->description,
                    $role->permissions->pluck('name')->implode('; '),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export roles to Excel (.xlsx).
     */
    public static function exportRolesExcel(): BinaryFileResponse
    {
        $filename = 'roles-permissions-'.now()->format('Ymd-His').'.xlsx';

        $export = new TableExport(
            query: Role::with('permissions')->orderBy('name'),
            headings: ['Role Name', 'Type', 'Color', 'Users Count', 'Permissions Count', 'Description', 'Permissions List'],
            mapper: fn (Role $role) => [
                $role->name,
                $role->is_system ? 'System Protected' : 'Custom Role',
                $role->color,
                $role->users()->count(),
                $role->permissions->count(),
                $role->description,
                $role->permissions->pluck('name')->implode('; '),
            ]
        );

        return Excel::download($export, $filename);
    }

    /**
     * Export roles & permissions matrix to PDF.
     */
    public static function exportRolesPdf(): Response
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        $permissions = Permission::orderBy('module')->orderBy('name')->get()->groupBy('module');
        $filename = 'roles-permissions-matrix-'.now()->format('Ymd-His').'.pdf';

        $pdf = Pdf::loadView('exports.roles-pdf', [
            'roles' => $roles,
            'permissions' => $permissions,
            'generatedAt' => now()->format('d M Y, h:i A'),
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * Export permissions list to CSV.
     */
    public static function exportPermissionsCsv(): StreamedResponse
    {
        $filename = 'permissions-directory-'.now()->format('Ymd-His').'.csv';
        $permissions = Permission::orderBy('module')->orderBy('name')->get();

        return response()->streamDownload(function () use ($permissions) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Module', 'Permission Key', 'Guard', 'Description']);

            foreach ($permissions as $perm) {
                fputcsv($handle, [
                    $perm->id,
                    $perm->module,
                    $perm->name,
                    $perm->guard_name,
                    $perm->description,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
