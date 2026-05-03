<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Performance Overview - Modeh</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; line-height: 1.5; margin: 0; padding: 0; }
        .page { padding: 40px; }
        .header { text-align: center; border-bottom: 2px solid {{ $brandColor }}; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 28px; font-weight: bold; color: {{ $brandColor }}; letter-spacing: -1px; }
        .title { font-size: 24px; margin-top: 10px; color: #0f172a; }
        .subtitle { font-size: 14px; color: #64748b; margin-top: 5px; }
        
        .student-info { margin-bottom: 30px; background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
        
        .section-title { font-size: 18px; font-weight: bold; color: #0f172a; margin-bottom: 20px; margin-top: 30px; border-left: 4px solid {{ $brandColor }}; padding-left: 12px; }
        
        .stats-grid { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .stats-item { width: 25%; text-align: center; padding: 15px; border: 1px solid #e2e8f0; background: #ffffff; }
        .stats-value { font-size: 22px; font-weight: bold; color: {{ $brandColor }}; }
        .stats-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        
        .card { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; background: #fff; }
        .card-header { margin-bottom: 10px; }
        .card-title { font-weight: bold; font-size: 15px; color: #0f172a; }
        .card-percentage { float: right; font-weight: bold; color: {{ $brandColor }}; }
        
        .progress-bar { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; border-radius: 4px; }
        
        .weak-item { border-left: 4px solid #ef4444; background: #fef2f2; }
        .strong-item { border-left: 4px solid #10b981; background: #ecfdf5; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table th { text-align: left; padding: 12px; background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 12px; color: #64748b; }
        .table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
        
        .footer { margin-top: 50px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 20px; }
        
        @media print {
            .page { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="logo">MODEH</div>
            <div class="title">Performance Overview Report</div>
            <div class="subtitle">Comprehensive Analysis of Learning Progress</div>
        </div>

        <div class="student-info">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%;">
                        <div style="font-size: 12px; color: #64748b;">PREPARED FOR</div>
                        <div style="font-size: 18px; font-weight: bold; color: #0f172a;">{{ $user->name }}</div>
                        <div style="font-size: 13px; color: #64748b;">{{ $user->email }}</div>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <div style="font-size: 12px; color: #64748b;">REPORT DATE</div>
                        <div style="font-size: 18px; font-weight: bold; color: #0f172a;">{{ date('F d, Y') }}</div>
                        <div style="font-size: 13px; color: #64748b;">Aggregate Platform Activity</div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="stats-grid">
            <tr>
                <td class="stats-item">
                    <div class="stats-value">{{ $data['stats']['total_quizzes'] }}</div>
                    <div class="stats-label">Quizzes</div>
                </td>
                <td class="stats-item">
                    <div class="stats-value">{{ $data['stats']['avg_score'] }}%</div>
                    <div class="stats-label">Avg. Score</div>
                </td>
                <td class="stats-item">
                    <div class="stats-value">{{ $data['stats']['total_questions'] }}</div>
                    <div class="stats-label">Questions</div>
                </td>
                <td class="stats-item">
                    <div class="stats-value">
                        @php
                            $sec = $data['stats']['total_time_seconds'];
                            $hrs = floor($sec / 3600);
                            $mins = floor(($sec % 3600) / 60);
                            echo $hrs > 0 ? "{$hrs}h {$mins}m" : "{$mins}m";
                        @endphp
                    </div>
                    <div class="stats-label">Time Spent</div>
                </td>
            </tr>
        </table>

        <div class="section-title">Learning Insights</div>
        <table class="stats-grid">
            <tr>
                <td class="stats-item" style="width: 33%;">
                    <div class="stats-value" style="color: {{ $data['stats']['proficiency']['color'] }}">{{ $data['stats']['proficiency']['grade'] }}</div>
                    <div class="stats-label">Estimated Grade</div>
                    <div style="font-size: 10px; color: #64748b;">{{ $data['stats']['proficiency']['label'] }}</div>
                </td>
                <td class="stats-item" style="width: 33%;">
                    <div class="stats-value">{{ $data['stats']['consistency'] }}%</div>
                    <div class="stats-label">Consistency</div>
                    <div style="font-size: 10px; color: #64748b;">Monthly Activity</div>
                </td>
                <td class="stats-item" style="width: 33%;">
                    <div class="stats-value">{{ $data['stats']['velocity'] }}</div>
                    <div class="stats-label">Trend Velocity</div>
                    <div style="font-size: 10px; color: #64748b;">Last 10 Attempts</div>
                </td>
            </tr>
        </table>

        <div class="section-title">Subject Mastery Breakdown</div>
        <table class="table">
            <thead>
                <tr>
                    <th>Subject Name</th>
                    <th style="text-align: right;">Mastery Level</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['subjects'] as $subject)
                <tr>
                    <td>{{ $subject['name'] }}</td>
                    <td style="width: 200px;">
                        <div style="text-align: right; font-weight: bold; margin-bottom: 4px;">{{ $subject['percentage'] }}%</div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: {{ $subject['percentage'] }}%; background: {{ $subject['percentage'] >= 80 ? '#10b981' : ($subject['percentage'] >= 60 ? '#3b82f6' : ($subject['percentage'] >= 40 ? '#f59e0b' : '#ef4444')) }}"></div>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if(count($data['weak_areas']) > 0)
        <div style="page-break-before: always;"></div>
        <div class="section-title">Priority Focus Areas (Weakest)</div>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">We recommend focusing your study time on these specific topics to see the fastest improvement in your overall scores.</p>
        
        @foreach($data['weak_areas'] as $topic)
        <div class="card weak-item">
            <div class="card-header">
                <span class="card-title">{{ $topic['name'] }}</span>
                <span class="card-percentage">{{ $topic['percentage'] }}%</span>
            </div>
            <div style="font-size: 13px; color: #475569;">
                Status: Needs Improvement • Practice Questions: {{ $topic['total'] }}
            </div>
        </div>
        @endforeach
        @endif

        @if(count($data['strong_areas']) > 0)
        <div class="section-title">Top Strengths</div>
        <div style="display: table; width: 100%;">
            @foreach($data['strong_areas'] as $topic)
            <div class="card strong-item" style="display: inline-block; width: 45%; margin-right: 2%; vertical-align: top;">
                <div class="card-header">
                    <span class="card-title">{{ $topic['name'] }}</span>
                    <span class="card-percentage">{{ $topic['percentage'] }}%</span>
                </div>
                <div style="font-size: 12px; color: #059669; font-weight: bold;">
                    MASTERY ACHIEVED
                </div>
            </div>
            @endforeach
        </div>
        @endif

        <div class="footer">
            <p>This report is generated automatically by Modeh Assessment Platform based on your quiz activity.</p>
            <p>© {{ date('Y') }} Modeh. All rights reserved. • https://modeh.co.ke</p>
        </div>
    </div>
</body>
</html>
