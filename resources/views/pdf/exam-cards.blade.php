<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Kartu Ujian - {{ $rombel->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .page-break {
            page-break-after: always;
        }
        .cards-container {
            width: 100%;
        }
        .card {
            width: 48%; /* 2 cards per row */
            height: 250px;
            border: 1px solid #000;
            border-radius: 5px;
            float: left;
            margin: 1%;
            box-sizing: border-box;
            padding: 10px;
            position: relative;
        }
        /* Clearfix for every 2 cards */
        .card:nth-child(2n+1) {
            clear: left;
        }
        .card-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .card-header h3 {
            margin: 0;
            font-size: 14px;
            text-transform: uppercase;
        }
        .card-header p {
            margin: 2px 0 0;
            font-size: 10px;
        }
        .photo-box {
            width: 60px;
            height: 80px;
            border: 1px solid #000;
            float: left;
            text-align: center;
            line-height: 80px;
            font-size: 10px;
            margin-right: 10px;
        }
        .info-box {
            float: left;
            width: calc(100% - 80px); /* calc to adjust width after photo */
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .table td {
            padding: 2px 0;
            vertical-align: top;
        }
        .label {
            width: 70px;
            font-weight: bold;
        }
        .colon {
            width: 10px;
            text-align: center;
        }
        .qr-code {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 50px;
            height: 50px;
            border: 1px solid #000;
            text-align: center;
            line-height: 50px;
            font-size: 8px;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>

    <div class="cards-container">
        @foreach($students as $index => $student)
            <div class="card">
                <div class="card-header">
                    <h3>KARTU PESERTA UJIAN</h3>
                    <p>{{ $examName }}</p>
                    <p>{{ $madrasahName }} - Tahun Ajaran {{ $rombel->academicYear->year }}</p>
                </div>
                
                <div class="clearfix">
                    <div class="photo-box">
                        3 x 4
                    </div>
                    <div class="info-box">
                        <table class="table">
                            <tr><td class="label">Nama</td><td class="colon">:</td><td>{{ strtoupper($student->name) }}</td></tr>
                            <tr><td class="label">NISN</td><td class="colon">:</td><td>{{ $student->nisn }}</td></tr>
                            <tr><td class="label">Rombel</td><td class="colon">:</td><td>{{ $rombel->name }}</td></tr>
                            <tr><td class="label">Ruang</td><td class="colon">:</td><td>_______</td></tr>
                        </table>
                    </div>
                </div>

                <div class="qr-code">
                    [QR]
                </div>
            </div>

            <!-- Page break every 8 cards (4 rows) -->
            @if(($index + 1) % 8 == 0 && !$loop->last)
                <div style="clear:both;"></div>
                <div class="page-break"></div>
            @endif
        @endforeach
        <div style="clear:both;"></div>
    </div>

</body>
</html>
