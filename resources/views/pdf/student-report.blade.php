<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Data Siswa</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 18px;
            text-transform: uppercase;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 12px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #999;
        }
        th {
            background-color: #f4f4f4;
            padding: 6px;
            text-align: center;
            font-weight: bold;
        }
        td {
            padding: 4px 6px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .footer {
            margin-top: 30px;
            width: 100%;
        }
        .signature {
            float: right;
            width: 250px;
            text-align: center;
        }
        .signature p {
            margin: 0 0 60px 0;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>
            @if($type === 'daftar_hadir')
                DAFTAR HADIR SISWA
            @elseif($type === 'form_nilai')
                FORM NILAI SISWA
            @else
                DAFTAR SISWA
            @endif
        </h1>
        <p>{{ $madrasahName }} | Tahun Ajaran Berjalan</p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="30">No</th>
                <th width="60">Kelas</th>
                @if($type === 'daftar_siswa')
                    <th width="80">NISM</th>
                    <th width="80">NISN</th>
                    <th>Nama Siswa</th>
                    <th width="30">L/P</th>
                    <th width="100">Tempat, Tgl Lahir</th>
                    <th width="150">Asal SD</th>
                @elseif($type === 'daftar_hadir')
                    <th width="80">NISN</th>
                    <th>Nama Siswa</th>
                    <th width="30">L/P</th>
                    @for($i=1; $i<=15; $i++)
                        <th width="20">{{ $i }}</th>
                    @endfor
                @elseif($type === 'form_nilai')
                    <th width="80">NISN</th>
                    <th>Nama Siswa</th>
                    <th width="30">L/P</th>
                    <th>Tugas 1</th>
                    <th>Tugas 2</th>
                    <th>Tugas 3</th>
                    <th>UTS</th>
                    <th>UAS</th>
                    <th>Total</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($students as $index => $student)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">{{ $student->currentCohort->rombel->name ?? '-' }}</td>
                    @if($type === 'daftar_siswa')
                        <td class="text-center">{{ $student->nis_local }}</td>
                        <td class="text-center">{{ $student->nisn }}</td>
                        <td>{{ $student->name }}</td>
                        <td class="text-center">{{ $student->gender }}</td>
                        <td>{{ $student->birth_place }}, {{ $student->birth_date ? $student->birth_date->format('d/m/Y') : '' }}</td>
                        <td>{{ $student->prev_school_name }}</td>
                    @elseif($type === 'daftar_hadir')
                        <td class="text-center">{{ $student->nisn }}</td>
                        <td>{{ $student->name }}</td>
                        <td class="text-center">{{ $student->gender }}</td>
                        @for($i=1; $i<=15; $i++)
                            <td></td>
                        @endfor
                    @elseif($type === 'form_nilai')
                        <td class="text-center">{{ $student->nisn }}</td>
                        <td>{{ $student->name }}</td>
                        <td class="text-center">{{ $student->gender }}</td>
                        <td></td><td></td><td></td><td></td><td></td><td></td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center">Tidak ada data siswa.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <div class="signature">
            <p>................., {{ date('d F Y') }}<br>Wali Kelas / Guru Mata Pelajaran</p>
            <strong>_________________________</strong>
        </div>
    </div>

</body>
</html>
