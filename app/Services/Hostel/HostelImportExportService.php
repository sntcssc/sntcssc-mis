<?php

namespace App\Services\Hostel;

use App\Exports\TableExport;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\User;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HostelImportExportService
{
    /**
     * Export allocations query to Excel (xlsx), CSV or Landscape PDF.
     *
     * @param  Builder<HostelAllocation>|string|null  $query
     */
    public static function export(
        Builder|string|null $query = null,
        string $format = 'xlsx',
        ?string $title = null,
    ): BinaryFileResponse|StreamedResponse|Response {
        if (is_string($query)) {
            $format = $query;
            $query = null;
        }

        $query = $query ?? HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
            'room.building',
            'room.floor',
            'bed',
        ]);

        $format = strtolower($format);

        // Dedicated Landscape A4 PDF export
        if ($format === 'pdf') {
            $allocations = $query->get();
            $filename = 'hostel-allocations-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-allocations-pdf', [
                'title' => $title ?? 'Hostel Room Allocations & Residents Register',
                'allocations' => $allocations,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        $headings = [
            'Allocation No',
            'Status',
            'Resident Name',
            'Resident Email',
            'Resident Phone',
            'Resident Type',
            'Student ID / URN',
            'Enrollment ID',
            'Enrolled Course',
            'Enrolled Batch',
            'Enrolled Section',
            'Building',
            'Floor',
            'Room Number',
            'Bed Number',
            'Room Type',
            'Gender Wing',
            'Start Date',
            'End Date',
            'Duration Type',
            'Duration Value',
            'Hardcopy Form Submitted',
            'Softcopy Form Uploaded',
            'Previous Batch Student',
            'Allocated At',
        ];

        $mapper = function (HostelAllocation $a): array {
            $user = $a->user;
            $room = $a->room;
            $bed = $a->bed;
            $building = $room?->building;
            $floor = $room?->floor;

            return [
                $a->allocation_no,
                ucfirst($a->status),
                $user?->name ?? 'N/A',
                $user?->email ?? 'N/A',
                $user?->phone ?? 'N/A',
                ucfirst($a->resident_type),
                $a->student_identifier,
                $a->enrollment_no,
                $a->course_name,
                $a->batch_name,
                $a->section_name,
                $building?->name ?? 'N/A',
                $floor?->name ?? 'N/A',
                $room?->room_number ?? 'N/A',
                $bed?->bed_number ?? 'N/A',
                $room ? ($room->isSharing() ? "Sharing ({$room->capacity} Beds)" : 'Single Non-Sharing') : 'N/A',
                $room?->gender_label ?? 'N/A',
                $a->start_date ? $a->start_date->format('d M Y') : 'N/A',
                $a->end_date ? $a->end_date->format('d M Y') : 'Indefinite',
                ucfirst($a->duration_type ?? 'indefinite'),
                $a->duration_value ? (string) $a->duration_value : 'N/A',
                $a->hard_copy_submitted ? 'Yes' : 'No',
                ! empty($a->soft_copy_path) ? 'Yes' : 'No',
                $a->is_previous_batch_student ? 'Yes' : 'No',
                $a->created_at ? $a->created_at->format('d M Y H:i') : 'N/A',
            ];
        };

        return TableExporter::download(
            $query,
            $format,
            'hostel-allocations',
            $headings,
            $mapper,
            $title ?? 'Hostel Accommodation Allocations'
        );
    }

    /**
     * Download sample import template CSV for allocations.
     */
    public static function downloadSampleTemplate(): StreamedResponse
    {
        $headers = [
            'student_email_or_urn',
            'building_code',
            'room_number',
            'bed_number',
            'start_date',
            'duration_type',
            'duration_value',
            'remarks',
        ];

        $sampleRows = [
            [
                'rahul.das@sntcssc.edu.in',
                'GH',
                'G-101',
                'Bed 1',
                now()->toDateString(),
                'months',
                '6',
                'Regular semester accommodation',
            ],
            [
                'STU-2026-0042',
                'MH',
                'M-201',
                'Bed 1',
                now()->toDateString(),
                'years',
                '1',
                'Civil Services Prelims batch accommodation',
            ],
        ];

        return TableExporter::csv($headers, $sampleRows, 'sntcssc-hostel-allocations-template.csv');
    }

    /**
     * Export buildings & floors to Excel, CSV, or Landscape PDF.
     */
    public static function exportBuildings(
        Builder|string|null $query = null,
        string $format = 'xlsx',
        ?string $title = null
    ): BinaryFileResponse|StreamedResponse|Response {
        if (is_string($query)) {
            $format = $query;
            $query = null;
        }

        $query = $query ?? HostelBuilding::withCount(['floors', 'rooms', 'beds'])->with(['caretakers'])->orderBy('display_order');
        $format = strtolower($format);
        $title = $title ?? 'Hostel Buildings & Floors Directory';

        $headings = [
            'Building Name',
            'Code',
            'Gender Wing',
            'Total Floors',
            'Manager Name',
            'Manager Contact',
            'Manager Email',
            'Warden Name',
            'Warden Contact',
            'Warden Email',
            'Caretakers Count',
            'Rooms Count',
            'Beds Count',
            'Status',
        ];

        $mapper = function (HostelBuilding $b): array {
            return [
                $b->name,
                $b->code,
                $b->gender_label,
                (string) $b->floors_count,
                $b->manager_name ?? 'N/A',
                $b->manager_contact ?? 'N/A',
                $b->manager_email ?? 'N/A',
                $b->warden_name ?? 'N/A',
                $b->warden_contact ?? 'N/A',
                $b->warden_email ?? 'N/A',
                (string) $b->caretakers->count(),
                (string) $b->rooms_count,
                (string) $b->beds_count,
                ucfirst($b->status),
            ];
        };

        if ($format === 'pdf') {
            $rows = $query->get()->map($mapper)->all();
            $filename = 'hostel-buildings-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-generic-landscape-pdf', [
                'title' => $title,
                'subtitle' => 'Residential Facilities, Management, Wardens and Capacity Matrix',
                'headers' => $headings,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        return TableExporter::download($query, $format, 'hostel-buildings', $headings, $mapper, $title);
    }

    /**
     * Download sample template for importing Buildings & Floors.
     */
    public static function downloadBuildingsSampleTemplate(): StreamedResponse
    {
        $headers = [
            'name',
            'code',
            'gender_type',
            'total_floors',
            'manager_name',
            'manager_contact',
            'manager_email',
            'warden_name',
            'warden_contact',
            'warden_email',
            'status',
            'description',
        ];

        $sampleRows = [
            [
                'Kanchenjunga Hostel',
                'KCH',
                'coed',
                '3',
                'Mr. Prosenjit Das',
                '+91 98300 12345',
                'manager.kch@sntcssc.edu.in',
                'Dr. Animesh Roy',
                '+91 98300 54321',
                'warden.kch@sntcssc.edu.in',
                'active',
                'New West Wing residential facility for UPSC aspirants',
            ],
            [
                'Subarnarekha Hostel',
                'SRH',
                'female_only',
                '2',
                'Mrs. Mousumi Banerjee',
                '+91 98311 22334',
                'manager.srh@sntcssc.edu.in',
                'Prof. Rita Guha',
                '+91 98311 55667',
                'warden.srh@sntcssc.edu.in',
                'active',
                'Female scholars residential building',
            ],
        ];

        return TableExporter::csv($headers, $sampleRows, 'sntcssc-hostel-buildings-template.csv');
    }

    /**
     * Batch import Buildings & auto-generate Floors from CSV or Excel.
     *
     * @return array{imported: int, success: int, skipped: int, errors: array<string>}
     */
    public static function importBuildings(string $filePath, ?int $importedBy = null): array
    {
        $rows = self::parseRowsFromFile($filePath);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            $code = strtoupper(trim((string) ($row['code'] ?? '')));

            if (empty($name) || empty($code)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Building name and code are mandatory.";

                continue;
            }

            $totalFloors = max(1, (int) ($row['total_floors'] ?? 1));
            $genderType = strtolower(trim((string) ($row['gender_type'] ?? 'coed')));
            if (! in_array($genderType, ['male_only', 'female_only', 'coed'], true)) {
                $genderType = 'coed';
            }

            try {
                $building = HostelBuilding::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'gender_type' => $genderType,
                        'total_floors' => $totalFloors,
                        'manager_name' => ! empty($row['manager_name']) ? trim((string) $row['manager_name']) : null,
                        'manager_contact' => ! empty($row['manager_contact']) ? trim((string) $row['manager_contact']) : null,
                        'manager_email' => ! empty($row['manager_email']) ? trim((string) $row['manager_email']) : null,
                        'warden_name' => ! empty($row['warden_name']) ? trim((string) $row['warden_name']) : null,
                        'warden_contact' => ! empty($row['warden_contact']) ? trim((string) $row['warden_contact']) : null,
                        'warden_email' => ! empty($row['warden_email']) ? trim((string) $row['warden_email']) : null,
                        'description' => ! empty($row['description']) ? trim((string) $row['description']) : null,
                        'status' => ! empty($row['status']) ? strtolower(trim((string) $row['status'])) : 'active',
                        'created_by' => $importedBy,
                        'updated_by' => $importedBy,
                    ]
                );

                // Auto provision floors if not present
                for ($fl = 1; $fl <= $totalFloors; $fl++) {
                    HostelFloor::firstOrCreate(
                        [
                            'hostel_building_id' => $building->id,
                            'floor_number' => $fl,
                        ],
                        [
                            'name' => "Floor {$fl}",
                            'gender_type' => $building->gender_type,
                            'status' => 'active',
                            'created_by' => $importedBy,
                        ]
                    );
                }

                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$rowNum} ('{$name}'): ".$e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'success' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export rooms & beds inventory to Excel, CSV, or Landscape PDF.
     */
    public static function exportRooms(
        Builder|array|string|null $query = null,
        string $format = 'xlsx',
        ?string $title = null
    ): BinaryFileResponse|StreamedResponse|Response {
        if (is_string($query)) {
            $format = $query;
            $query = null;
        } elseif (is_array($query)) {
            $filters = $query;
            $query = HostelRoom::with(['building', 'floor', 'beds']);

            if (! empty($filters['building_id'])) {
                $query->where('hostel_building_id', $filters['building_id']);
            }
            if (! empty($filters['floor_id'])) {
                $query->where('hostel_floor_id', $filters['floor_id']);
            }
            if (! empty($filters['room_type'])) {
                $query->where('room_type', $filters['room_type']);
            }
            if (! empty($filters['gender_allowed'])) {
                $query->where('gender_allowed', $filters['gender_allowed']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['search'])) {
                $s = $filters['search'];
                $query->where('room_number', 'like', "%{$s}%");
            }
        }

        $query = $query ?? HostelRoom::with(['building', 'floor', 'beds'])->orderBy('room_number');
        $format = strtolower($format);
        $title = $title ?? 'Hostel Rooms & Beds Inventory';

        $headings = [
            'Building',
            'Floor',
            'Room Number',
            'Room Type',
            'Capacity',
            'Gender Allowed',
            'Monthly Charge',
            'Total Beds',
            'Available Beds',
            'Occupied Beds',
            'Reserved Beds',
            'Maintenance Beds',
            'Amenities',
            'Status',
        ];

        $mapper = function (HostelRoom $r): array {
            $beds = $r->beds;
            $amenities = is_array($r->amenities) ? implode(', ', $r->amenities) : ($r->amenities ?? 'N/A');

            return [
                $r->building?->name ?? 'N/A',
                $r->floor?->name ?? 'N/A',
                $r->room_number,
                $r->isSharing() ? "Sharing ({$r->capacity} Beds)" : 'Single Non-Sharing',
                (string) $r->capacity,
                $r->gender_label,
                $r->monthly_charge ? 'Rs. '.number_format($r->monthly_charge, 2) : 'Free',
                (string) $beds->count(),
                (string) $beds->where('status', 'available')->count(),
                (string) $beds->where('status', 'occupied')->count(),
                (string) $beds->where('status', 'reserved')->count(),
                (string) $beds->where('status', 'maintenance')->count(),
                $amenities,
                ucfirst($r->status),
            ];
        };

        if ($format === 'pdf') {
            $rows = $query->get()->map($mapper)->all();
            $filename = 'hostel-rooms-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-generic-landscape-pdf', [
                'title' => $title,
                'subtitle' => 'Rooms Capacity, Sharing Types, Gender Designations and Bed Status Roster',
                'headers' => $headings,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        return TableExporter::download($query, $format, 'hostel-rooms', $headings, $mapper, $title);
    }

    /**
     * Download sample template for importing Rooms & Beds.
     */
    public static function downloadRoomsSampleTemplate(): StreamedResponse
    {
        $headers = [
            'building_code',
            'floor_number',
            'room_number',
            'room_type',
            'capacity',
            'gender_allowed',
            'monthly_charge',
            'amenities',
            'status',
            'notes',
        ];

        $sampleRows = [
            [
                'GH',
                '1',
                'G-105',
                'sharing',
                '3',
                'male_only',
                '2500',
                'WiFi, Geyser, Study Table, Balcony',
                'available',
                'First floor west wing sharing room',
            ],
            [
                'MH',
                '2',
                'M-204',
                'non_sharing',
                '1',
                'female_only',
                '4000',
                'AC, WiFi, Attached Bath, Study Lamp',
                'available',
                'Single occupancy faculty or senior scholar room',
            ],
            [
                'TH',
                '1',
                'T-102',
                'sharing',
                '2',
                'male_only',
                '3000',
                'WiFi, Study Table',
                'available',
                'Standard twin sharing accommodation',
            ],
        ];

        return TableExporter::csv($headers, $sampleRows, 'sntcssc-hostel-rooms-template.csv');
    }

    /**
     * Batch import Rooms & provision Beds from CSV or Excel.
     *
     * @return array{imported: int, success: int, skipped: int, errors: array<string>}
     */
    public static function importRooms(string $filePath, ?int $importedBy = null): array
    {
        $rows = self::parseRowsFromFile($filePath);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $buildingCode = strtoupper(trim((string) ($row['building_code'] ?? ($row['building_name'] ?? ''))));
            $floorNumber = max(1, (int) ($row['floor_number'] ?? 1));
            $roomNumber = trim((string) ($row['room_number'] ?? ''));

            if (empty($buildingCode) || empty($roomNumber)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Building code and room number are mandatory.";

                continue;
            }

            // Find building
            $building = HostelBuilding::where('code', $buildingCode)
                ->orWhere('name', 'like', "%{$buildingCode}%")
                ->first();

            if (! $building) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Building '{$buildingCode}' not found.";

                continue;
            }

            // Find or create floor
            $floor = HostelFloor::firstOrCreate(
                [
                    'hostel_building_id' => $building->id,
                    'floor_number' => $floorNumber,
                ],
                [
                    'name' => "Floor {$floorNumber}",
                    'gender_type' => $building->gender_type,
                    'status' => 'active',
                    'created_by' => $importedBy,
                ]
            );

            $roomType = strtolower(trim((string) ($row['room_type'] ?? 'sharing')));
            if (! in_array($roomType, ['sharing', 'non_sharing'], true)) {
                $roomType = 'sharing';
            }

            $capacity = $roomType === 'non_sharing' ? 1 : max(1, (int) ($row['capacity'] ?? 2));

            $genderAllowed = strtolower(trim((string) ($row['gender_allowed'] ?? 'male_only')));
            if (! in_array($genderAllowed, ['male_only', 'female_only', 'any'], true)) {
                $genderAllowed = $building->gender_type === 'female_only' ? 'female_only' : ($building->gender_type === 'male_only' ? 'male_only' : 'any');
            }

            $monthlyCharge = ! empty($row['monthly_charge']) ? (float) $row['monthly_charge'] : 0.0;
            $amenitiesRaw = trim((string) ($row['amenities'] ?? ''));
            $amenities = ! empty($amenitiesRaw) ? array_map('trim', explode(',', $amenitiesRaw)) : [];

            try {
                $room = HostelRoom::updateOrCreate(
                    [
                        'hostel_building_id' => $building->id,
                        'room_number' => $roomNumber,
                    ],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => $roomType,
                        'capacity' => $capacity,
                        'gender_allowed' => $genderAllowed,
                        'monthly_charge' => $monthlyCharge,
                        'amenities' => $amenities,
                        'status' => ! empty($row['status']) ? strtolower(trim((string) $row['status'])) : 'available',
                        'notes' => ! empty($row['notes']) ? trim((string) $row['notes']) : null,
                        'created_by' => $importedBy,
                        'updated_by' => $importedBy,
                    ]
                );

                // Auto provision beds up to capacity
                $existingBedsCount = $room->beds()->count();
                for ($b = 1; $b <= $capacity; $b++) {
                    $bedNumber = "Bed-{$b}";
                    HostelBed::firstOrCreate(
                        [
                            'hostel_room_id' => $room->id,
                            'bed_number' => $bedNumber,
                        ],
                        [
                            'bed_identifier' => "{$room->room_number}-{$bedNumber}",
                            'status' => 'available',
                            'created_by' => $importedBy,
                        ]
                    );
                }

                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$rowNum} ('{$roomNumber}'): ".$e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'success' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export Floor Matrix Report to Excel, CSV or Landscape PDF.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function exportReportsMatrix(
        Collection|array $rows,
        string $format = 'xlsx',
        ?string $title = null
    ): BinaryFileResponse|StreamedResponse|Response {
        if ($rows instanceof Collection) {
            $rows = $rows->toArray();
        }

        $format = strtolower($format);
        $title = $title ?? 'Hostel Floor-Wise Capacity & Occupancy Matrix';

        $headings = [
            'Building Name',
            'Code',
            'Floor',
            'Gender Wing',
            'Total Rooms',
            'Total Beds',
            'Occupied',
            'Available',
            'Reserved',
            'Maintenance',
            'Occupancy Rate (%)',
        ];

        $formattedRows = array_map(function ($r): array {
            $r = (array) $r;

            return [
                $r['building_name'] ?? $r['building'] ?? 'N/A',
                $r['building_code'] ?? $r['code'] ?? 'N/A',
                $r['floor_name'] ?? $r['floor'] ?? (isset($r['floors_count']) ? (string) $r['floors_count'] : 'N/A'),
                $r['gender_type'] ?? $r['gender_designation'] ?? $r['gender_allowed'] ?? 'All',
                (string) ($r['total_rooms'] ?? $r['rooms_count'] ?? 0),
                (string) ($r['total_beds'] ?? $r['total_capacity'] ?? 0),
                (string) ($r['occupied'] ?? $r['occupied_beds'] ?? 0),
                (string) ($r['available'] ?? $r['available_beds'] ?? 0),
                (string) ($r['reserved'] ?? $r['reserved_beds'] ?? 0),
                (string) ($r['maintenance'] ?? $r['maintenance_beds'] ?? 0),
                rtrim((string) ($r['rate'] ?? $r['occupancy_rate'] ?? 0), '%').'%',
            ];
        }, $rows);

        if ($format === 'pdf') {
            $filename = 'hostel-matrix-report-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-generic-landscape-pdf', [
                'title' => $title,
                'subtitle' => 'Live Residential Density, Total Bed Availability and Occupancy Percentages',
                'headers' => $headings,
                'rows' => $formattedRows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        if ($format === 'csv') {
            return TableExporter::csv($headings, $formattedRows, 'hostel-matrix-report-'.now()->format('Ymd-His').'.csv');
        }

        // Export to XLSX
        $filename = 'hostel-matrix-report-'.now()->format('Ymd-His').'.xlsx';
        $export = new TableExport(
            HostelBuilding::query(),
            $headings,
            fn () => []
        );

        return Excel::download(
            new class($headings, $formattedRows) implements FromArray, ShouldAutoSize, WithHeadings
            {
                public function __construct(public array $headings, public array $rows) {}

                public function headings(): array
                {
                    return $this->headings;
                }

                public function array(): array
                {
                    return $this->rows;
                }
            },
            $filename
        );
    }

    /**
     * Export Admission Form Compliance Report to Excel, CSV, or Landscape PDF.
     */
    public static function exportReportsCompliance(
        string $format = 'xlsx',
        ?string $title = null
    ): BinaryFileResponse|StreamedResponse|Response {
        $query = HostelAllocation::with(['user.studentProfile', 'room.building', 'bed'])->active()->orderBy('id', 'desc');
        $format = strtolower($format);
        $title = $title ?? 'Hostel Admission Form Compliance Audit';

        $headings = [
            'Allocation No',
            'Resident Name',
            'Resident Type',
            'Student ID / URN',
            'Phone',
            'Email',
            'Building',
            'Room Number',
            'Bed Number',
            'Hardcopy Form Submitted',
            'Hardcopy Date',
            'Softcopy Uploaded',
            'Status',
        ];

        $mapper = function (HostelAllocation $a): array {
            return [
                $a->allocation_no,
                $a->user?->name ?? 'N/A',
                ucfirst($a->resident_type),
                $a->student_identifier,
                $a->user?->phone ?? 'N/A',
                $a->user?->email ?? 'N/A',
                $a->room?->building?->name ?? 'N/A',
                $a->room?->room_number ?? 'N/A',
                $a->bed?->bed_number ?? 'N/A',
                $a->hard_copy_submitted ? 'Yes' : 'No',
                $a->hard_copy_submitted_at ? $a->hard_copy_submitted_at->format('d M Y') : 'Pending',
                ! empty($a->soft_copy_path) ? 'Yes' : 'Pending',
                ucfirst($a->status),
            ];
        };

        if ($format === 'pdf') {
            $rows = $query->get()->map($mapper)->all();
            $filename = 'hostel-compliance-report-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-generic-landscape-pdf', [
                'title' => $title,
                'subtitle' => 'Physical & Digital Admission Form Undertakings Audit by Resident',
                'headers' => $headings,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        return TableExporter::download($query, $format, 'hostel-compliance-report', $headings, $mapper, $title);
    }

    /**
     * Export 30-Day Stay Expirations Forecast Report to Excel, CSV, or Landscape PDF.
     */
    public static function exportReportsExpiring(
        string $format = 'xlsx',
        ?string $title = null
    ): BinaryFileResponse|StreamedResponse|Response {
        $query = HostelAllocation::with(['user.studentProfile', 'room.building', 'bed'])
            ->active()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->orderBy('end_date');

        $format = strtolower($format);
        $title = $title ?? 'Hostel Upcoming Stay Expirations Forecast (Next 30 Days)';

        $headings = [
            'Allocation No',
            'Resident Name',
            'Student ID / URN',
            'Phone',
            'Email',
            'Building',
            'Room Number',
            'Bed Number',
            'Check-in Date',
            'Scheduled End Date',
            'Days Remaining',
            'Status',
        ];

        $mapper = function (HostelAllocation $a): array {
            return [
                $a->allocation_no,
                $a->user?->name ?? 'N/A',
                $a->student_identifier,
                $a->user?->phone ?? 'N/A',
                $a->user?->email ?? 'N/A',
                $a->room?->building?->name ?? 'N/A',
                $a->room?->room_number ?? 'N/A',
                $a->bed?->bed_number ?? 'N/A',
                $a->start_date ? $a->start_date->format('d M Y') : 'N/A',
                $a->end_date ? $a->end_date->format('d M Y') : 'N/A',
                (string) ($a->daysRemaining() ?? 0).' Days',
                ucfirst($a->status),
            ];
        };

        if ($format === 'pdf') {
            $rows = $query->get()->map($mapper)->all();
            $filename = 'hostel-expirations-report-'.now()->format('Ymd-His').'.pdf';
            $pdf = Pdf::loadView('pdf.hostel-generic-landscape-pdf', [
                'title' => $title,
                'subtitle' => 'Scheduled Move-Outs, Extension Window Eligibility and Capacity Churn Forecast',
                'headers' => $headings,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                $filename,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]
            );
        }

        return TableExporter::download($query, $format, 'hostel-expirations-report', $headings, $mapper, $title);
    }

    /**
     * Parse raw rows from uploaded CSV or Excel file.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseRowsFromFile(string $filePath): array
    {
        $rows = [];
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            if (($handle = fopen($filePath, 'r')) !== false) {
                $header = null;
                while (($data = fgetcsv($handle, 4000, ',')) !== false) {
                    if (! $header) {
                        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $data);
                    } else {
                        if (count($header) === count($data)) {
                            $rows[] = array_combine($header, $data);
                        }
                    }
                }
                fclose($handle);
            }
        } else {
            try {
                $import = new class implements ToArray
                {
                    public function array(array $array): void {}
                };

                $sheet = Excel::toArray($import, $filePath);
                if (! empty($sheet) && ! empty($sheet[0])) {
                    $sheetRows = $sheet[0];
                    $rawHeader = array_shift($sheetRows);
                    $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), (array) $rawHeader);
                    foreach ($sheetRows as $r) {
                        if (count($header) === count($r)) {
                            $rows[] = array_combine($header, $r);
                        }
                    }
                }
            } catch (Throwable) {
                if (($handle = fopen($filePath, 'r')) !== false) {
                    $header = null;
                    while (($data = fgetcsv($handle, 4000, ',')) !== false) {
                        if (! $header) {
                            $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $data);
                        } else {
                            if (count($header) === count($data)) {
                                $rows[] = array_combine($header, $data);
                            }
                        }
                    }
                    fclose($handle);
                }
            }
        }

        return $rows;
    }

    /**
     * Import accommodation allocations from an uploaded spreadsheet file.
     *
     * @return array{imported: int, success: int, skipped: int, errors: array<string>}
     */
    public static function importAllocations(string $filePath, ?int $importedBy = null): array
    {
        $rows = self::parseRowsFromFile($filePath);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $userIdentifier = trim((string) ($row['student_email_or_urn'] ?? ($row['student_email'] ?? ($row['urn'] ?? ''))));
            $buildingCode = trim((string) ($row['building_code'] ?? ''));
            $roomNumber = trim((string) ($row['room_number'] ?? ''));
            $bedNumber = trim((string) ($row['bed_number'] ?? ''));

            if (empty($userIdentifier)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Student email or URN is missing.";

                continue;
            }

            // Find user
            $user = User::query()
                ->where('email', $userIdentifier)
                ->orWhereHas('studentProfile', function ($q) use ($userIdentifier) {
                    $q->where('urn', $userIdentifier)
                        ->orWhere('student_id', $userIdentifier);
                })
                ->first();

            if (! $user) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Student/Resident '{$userIdentifier}' could not be located in records.";

                continue;
            }

            // Find Building
            $building = HostelBuilding::where('code', strtoupper($buildingCode))
                ->orWhere('name', 'like', "%{$buildingCode}%")
                ->first();

            if (! $building) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Hostel Building '{$buildingCode}' not found.";

                continue;
            }

            // Find Room
            $room = HostelRoom::where('hostel_building_id', $building->id)
                ->where('room_number', $roomNumber)
                ->first();

            if (! $room) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Room '{$roomNumber}' not found in Building '{$building->name}'.";

                continue;
            }

            // Find Bed
            $bed = HostelBed::where('hostel_room_id', $room->id)
                ->where(function ($q) use ($bedNumber) {
                    $q->where('bed_number', $bedNumber)
                        ->orWhere('bed_identifier', $bedNumber);
                })
                ->first();

            if (! $bed) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Bed '{$bedNumber}' not found in Room '{$room->room_number}'.";

                continue;
            }

            if ($bed->status !== 'available') {
                $skipped++;
                $errors[] = "Row {$rowNum}: Bed '{$bed->bed_number}' in Room '{$room->room_number}' is {$bed->status}.";

                continue;
            }

            // Check if user already holds active allocation
            $existing = HostelAllocation::active()->where('user_id', $user->id)->first();
            if ($existing) {
                $skipped++;
                $errors[] = "Row {$rowNum}: User '{$user->name}' already holds an active allocation in Room {$existing->room?->room_number}.";

                continue;
            }

            try {
                $durationUnit = ! empty($row['duration_unit']) ? trim((string) $row['duration_unit']) : (! empty($row['duration_type']) ? trim((string) $row['duration_type']) : 'months');
                $isPrevBatch = ! empty($row['is_previous_batch']) && in_array(strtolower(trim((string) $row['is_previous_batch'])), ['1', 'yes', 'true'], true);
                $hardCopySubmitted = ! empty($row['hard_copy_form_submitted']) && in_array(strtolower(trim((string) $row['hard_copy_form_submitted'])), ['1', 'yes', 'true'], true);

                HostelService::allocateResident([
                    'user_id' => $user->id,
                    'hostel_room_id' => $room->id,
                    'hostel_bed_id' => $bed->id,
                    'start_date' => ! empty($row['start_date']) ? trim((string) $row['start_date']) : now()->toDateString(),
                    'duration_unit' => $durationUnit,
                    'duration_type' => $durationUnit,
                    'duration_value' => ! empty($row['duration_value']) ? (int) $row['duration_value'] : 6,
                    'is_previous_batch_student' => $isPrevBatch,
                    'previous_batch_identifier' => ! empty($row['previous_batch_identifier']) ? trim((string) $row['previous_batch_identifier']) : null,
                    'hard_copy_form_submitted' => $hardCopySubmitted,
                    'remarks' => ! empty($row['remarks']) ? trim((string) $row['remarks']) : 'Batch imported',
                ], $importedBy);

                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$rowNum} for '{$user->name}': ".$e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'success' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
