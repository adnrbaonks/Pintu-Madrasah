<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Biodata - {{ $student->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            text-transform: uppercase;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 12px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table td {
            padding: 6px 0;
            vertical-align: top;
        }
        .label {
            width: 150px;
            font-weight: bold;
        }
        .colon {
            width: 20px;
            text-align: center;
        }
        .value {
            border-bottom: 1px dotted #ccc;
        }
        .section-title {
            margin-top: 30px;
            margin-bottom: 10px;
            font-weight: bold;
            font-size: 16px;
            background-color: #f0f0f0;
            padding: 5px;
        }
        .qr-code {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 80px;
            height: 80px;
            border: 1px solid #ccc;
            text-align: center;
            line-height: 80px;
            font-size: 10px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h2>{{ $madrasahName }}</h2>
        <p>{{ $madrasahAddress }}</p>
    </div>

    <!-- Placeholder for QR code. Laravel DomPDF supports img tag, so we could generate a base64 QR code and put it here -->
    <div class="qr-code">
        [QR CODE]
    </div>

    <h3 style="text-align: center;">BIODATA PESERTA DIDIK</h3>

    <div class="section-title">A. IDENTITAS DIRI</div>
    <table class="table">
        <tr><td class="label">Nama Lengkap</td><td class="colon">:</td><td class="value">{{ $student->name }}</td></tr>
        <tr><td class="label">NISN</td><td class="colon">:</td><td class="value">{{ $student->nisn }}</td></tr>
        <tr><td class="label">NIK</td><td class="colon">:</td><td class="value">{{ $student->nik }}</td></tr>
        <tr><td class="label">Jenis Kelamin</td><td class="colon">:</td><td class="value">{{ $student->gender_label }}</td></tr>
        <tr><td class="label">Tempat, Tanggal Lahir</td><td class="colon">:</td><td class="value">{{ $student->birth_place }}, {{ $student->birth_date?->format('d-m-Y') }}</td></tr>
        <tr><td class="label">Agama</td><td class="colon">:</td><td class="value">{{ $student->religion }}</td></tr>
        <tr><td class="label">Alamat</td><td class="colon">:</td><td class="value">{{ $student->address }}, RT {{ $student->rt }} / RW {{ $student->rw }}, Kel. {{ $student->village }}</td></tr>
    </table>

    <div class="section-title">B. DATA ORANG TUA</div>
    <table class="table">
        <tr><td class="label">Nama Ayah</td><td class="colon">:</td><td class="value">{{ $student->father_name }}</td></tr>
        <tr><td class="label">Pekerjaan Ayah</td><td class="colon">:</td><td class="value">{{ $student->father_job }}</td></tr>
        <tr><td class="label">Nama Ibu</td><td class="colon">:</td><td class="value">{{ $student->mother_name }}</td></tr>
        <tr><td class="label">Pekerjaan Ibu</td><td class="colon">:</td><td class="value">{{ $student->mother_job }}</td></tr>
    </table>

    <div class="section-title">C. DATA AKADEMIK</div>
    <table class="table">
        <tr><td class="label">Rombel Saat Ini</td><td class="colon">:</td><td class="value">{{ $student->currentRombel?->name ?? 'Belum masuk rombel' }}</td></tr>
        <tr><td class="label">Tahun Ajaran</td><td class="colon">:</td><td class="value">{{ $student->currentCohort?->academicYear?->full_label ?? '-' }}</td></tr>
        <tr><td class="label">Status Siswa</td><td class="colon">:</td><td class="value">{{ $student->status_label }}</td></tr>
    </table>

    <div style="margin-top: 50px; text-align: right;">
        <p>Mengetahui,</p>
        <br><br><br>
        <p><strong>Kepala Madrasah</strong></p>
    </div>

</body>
</html>
