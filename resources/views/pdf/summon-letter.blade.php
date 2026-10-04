<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Pemanggilan Orang Tua</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #222; }
        .kop { text-align: center; border-bottom: 3px double #222; padding-bottom: 10px; margin-bottom: 20px; }
        .kop h2 { margin: 0; text-transform: uppercase; font-size: 18px; }
        .kop p { margin: 2px 0; font-size: 12px; }
        .nomor { text-align: center; margin: 16px 0; }
        .nomor u { font-weight: bold; font-size: 15px; }
        table.biodata { margin: 10px 0 10px 30px; }
        table.biodata td { padding: 3px 0; vertical-align: top; }
        .ttd { margin-top: 40px; width: 100%; }
        .ttd td { width: 50%; vertical-align: top; }
        .ttd .kanan { text-align: center; }
    </style>
</head>
<body>
    <div class="kop">
        <h2>{{ $madrasahName }}</h2>
        <p>{{ $madrasahAddress }}</p>
        @if($nsm)<p>NSM: {{ $nsm }}</p>@endif
    </div>

    <div class="nomor">
        <u>SURAT PEMANGGILAN ORANG TUA / WALI</u><br>
        <span>Nomor: ......./BK/{{ date('m') }}/{{ date('Y') }}</span>
    </div>

    <p>Assalamu'alaikum Wr. Wb.</p>
    <p>Sehubungan dengan pembinaan kedisiplinan siswa, kami mengharap kehadiran Bapak/Ibu orang tua/wali dari:</p>

    <table class="biodata">
        <tr><td width="160">Nama Siswa</td><td width="20">:</td><td><strong>{{ $record->student->name }}</strong></td></tr>
        <tr><td>NISN</td><td>:</td><td>{{ $record->student->nisn ?? '-' }}</td></tr>
        <tr><td>Kelas</td><td>:</td><td>{{ $record->student->grade_level ?? '-' }}</td></tr>
        <tr><td>Perihal</td><td>:</td><td>{{ $record->title }} ({{ $record->category }})</td></tr>
        <tr><td>Tanggal Catatan</td><td>:</td><td>{{ $record->record_date->format('d-m-Y') }}</td></tr>
    </table>

    @if($record->description)
    <p><strong>Uraian:</strong><br>{{ $record->description }}</p>
    @endif
    @if($record->follow_up)
    <p><strong>Tindak lanjut yang diharapkan:</strong><br>{{ $record->follow_up }}</p>
    @endif

    <p>Untuk hadir di madrasah pada jam kerja guna berkoordinasi dengan Guru BK/Wali Kelas. Atas perhatian dan kerja sama Bapak/Ibu, kami ucapkan terima kasih.</p>
    <p>Wassalamu'alaikum Wr. Wb.</p>

    <table class="ttd">
        <tr>
            <td></td>
            <td class="kanan">
                ..., {{ date('d-m-Y') }}<br>
                Kepala Madrasah,<br><br><br><br><br>
                <u><strong>{{ $headmaster ?: '( .................................... )' }}</strong></u><br>
                @if($headmasterNip)NIP. {{ $headmasterNip }}@endif
            </td>
        </tr>
    </table>
</body>
</html>
