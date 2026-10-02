<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Letter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'number', 'number_seq', 'subject', 'category',
        'letter_date', 'received_date', 'from_to', 'description',
        'attachment_path', 'status', 'disposisi', 'academic_year_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'letter_date'   => 'date',
            'received_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Label kategori */
    public function getCategoryLabelAttribute(): string
    {
        return [
            'umum'          => 'Umum',
            'undangan'      => 'Undangan',
            'sk'            => 'Surat Keputusan (SK)',
            'keterangan'    => 'Surat Keterangan',
            'rekomendasi'   => 'Rekomendasi',
            'permohonan'    => 'Permohonan',
            'pemberitahuan' => 'Pemberitahuan',
            'edaran'        => 'Edaran',
            'lainnya'       => 'Lainnya',
        ][$this->category] ?? ucfirst($this->category);
    }

    /**
     * Generate nomor surat keluar otomatis.
     * Format: 001/SK/MTS.NM/X/2026
     *   001      = nomor urut bulan ini
     *   SK       = singkatan kategori
     *   MTS.NM   = kode lembaga (dari setting nsm singkat)
     *   X        = Romawi bulan
     *   2026     = tahun
     */
    public static function generateOutNumber(string $category = 'umum', ?string $prefix = null): string
    {
        $roman = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $year  = now()->year;
        $month = now()->month;

        // Urutan di bulan + tahun ini
        $seq = self::where('type', 'keluar')
            ->whereYear('letter_date', $year)
            ->whereMonth('letter_date', $month)
            ->count() + 1;

        $seqStr = str_pad($seq, 3, '0', STR_PAD_LEFT);

        $catCode = match ($category) {
            'sk'            => 'SK',
            'undangan'      => 'UND',
            'keterangan'    => 'SKET',
            'rekomendasi'   => 'RKM',
            'permohonan'    => 'PMH',
            'pemberitahuan' => 'PBT',
            'edaran'        => 'SE',
            default         => 'SRT',
        };

        $lembaga = $prefix ?? 'MTS.NM';

        return "{$seqStr}/{$catCode}/{$lembaga}/{$roman[$month]}/{$year}";
    }
}
