<?php

namespace App\Exports;

use App\Models\Student\StudentProgram;
use App\Models\Master\Year;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ApplicantReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
{
    protected $yearId;
    protected $institutionId;
    protected $programId;
    protected $status;
    protected $boardingId;
    protected $isOperator;

    public function __construct($yearId = null, $institutionId = null, $programId = null, $status = null, $boardingId = null, $isOperator = false)
    {
        $this->yearId        = $yearId;
        $this->institutionId = $institutionId;
        $this->programId     = $programId;
        $this->status        = $status;
        $this->boardingId    = $boardingId;
        $this->isOperator    = $isOperator;
    }

    public function collection()
    {
        $relations = $this->isOperator
            ? ['personal', 'origin', 'program', 'boarding', 'verification']
            : ['personal', 'institution', 'program', 'boarding', 'verification'];

        $query = StudentProgram::with($relations)
            ->when($this->yearId, fn($q) => $q->where('yearId', $this->yearId))
            ->when($this->institutionId, fn($q) => $q->where('institutionId', $this->institutionId))
            ->when($this->programId, fn($q) => $q->where('programId', $this->programId))
            ->when($this->boardingId, fn($q) => $q->where('boardingId', $this->boardingId))
            ->when($this->status === 'verified', fn($q) => $q->has('verification'))
            ->when($this->status === 'pending', fn($q) => $q->doesntHave('verification'));

        return $query->get();
    }

    public function headings(): array
    {
        $yearName = '';
        if ($this->yearId) {
            $year = Year::find($this->yearId);
            $yearName = $year ? $year->name : '';
        }

        $columns = $this->isOperator
            ? ['ID', 'Nama', 'NISN', 'Jenis Kelamin', 'Program', 'Boarding', 'Sekolah Asal', 'Status Verifikasi', 'Tanggal Pendaftaran']
            : ['ID', 'Nama', 'NISN', 'Jenis Kelamin', 'Lembaga', 'Program', 'Boarding', 'Status Verifikasi', 'Tanggal Pendaftaran'];

        return [
            ['LAPORAN PENDAFTAR ' . ($yearName ? 'TAHUN PELAJARAN ' . $yearName : '')],
            $columns,
        ];
    }

    public function map($student): array
    {
        if ($this->isOperator) {
            return [
                $student->id,
                $student->personal->name ?? '-',
                $student->personal->nisn ?? '-',
                $student->personal->gender ? ($student->personal->gender == 1 ? 'Laki-laki' : 'Perempuan') : '-',
                $student->program->name ?? '-',
                $student->boarding->name ?? 'Non Boarding',
                $student->origin->name ?? '-',
                $student->verification !== null ? 'Terverifikasi' : 'Pending',
                $student->created_at->format('d/m/Y H:i'),
            ];
        }

        return [
            $student->id,
            $student->personal->name ?? '-',
            $student->personal->nisn ?? '-',
            $student->personal->gender ? ($student->personal->gender == 1 ? 'Laki-laki' : 'Perempuan') : '-',
            $student->institution->surname ?? '-',
            $student->program->name ?? '-',
            $student->boarding->name ?? 'Non Boarding',
            $student->verification !== null ? 'Terverifikasi' : 'Pending',
            $student->created_at->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Merge title cells
                $sheet->mergeCells('A1:' . $highestColumn . '1');

                // Add borders to the entire table
                $sheet->getStyle('A2:' . $highestColumn . $highestRow)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FF000000'],
                        ],
                    ],
                ]);
            },
        ];
    }
}
