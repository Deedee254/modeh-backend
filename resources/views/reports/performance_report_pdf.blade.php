<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Performance Report</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; line-height: 1.5; }
        .header { text-align: center; border-bottom: 2px solid {{ $brandColor }}; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 28px; font-weight: bold; color: {{ $brandColor }}; }
        .title { font-size: 24px; margin-top: 10px; }
        .student-info { margin-bottom: 30px; background: #f9fafb; padding: 15px; border-radius: 8px; }
        .section-title { font-size: 18px; font-weight: bold; color: {{ $brandColor }}; margin-bottom: 15px; border-left: 4px solid {{ $brandColor }}; padding-left: 10px; }
        .stats-grid { display: table; width: 100%; margin-bottom: 30px; }
        .stats-item { display: table-cell; text-align: center; padding: 10px; border: 1px solid #e5e7eb; }
        .stats-value { font-size: 20px; font-weight: bold; }
        .stats-label { font-size: 12px; color: #6b7280; }
        .topic-card { margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; }
        .topic-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .topic-name { font-weight: bold; font-size: 16px; }
        .topic-score { font-weight: bold; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-Strong { background: #dcfce7; color: #166534; }
        .status-Good { background: #dbeafe; color: #1e40af; }
        .status-Average { background: #fef3c7; color: #92400e; }
        .status-Weak { background: #fee2e2; color: #991b1b; }
        .recommendation { font-size: 13px; color: #4b5563; font-style: italic; margin-top: 5px; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">MODEH</div>
        <div class="title">Personalized Performance Report</div>
    </div>

    <div class="student-info">
        <table style="width: 100%;">
            <tr>
                <td><strong>Student:</strong> {{ $user->name }}</td>
                <td style="text-align: right;"><strong>Date:</strong> {{ $attempt->created_at->format('M d, Y') }}</td>
            </tr>
            <tr>
                <td><strong>Quiz:</strong> {{ $attempt->quiz->title }}</td>
                <td style="text-align: right;"><strong>Overall Score:</strong> {{ $attempt->score }}%</td>
            </tr>
        </table>
    </div>

    <div class="section-title">Topic Breakdown</div>
    @foreach($report['topics_breakdown'] as $topic)
        <div class="topic-card">
            <div style="margin-bottom: 5px;">
                <span class="topic-name">{{ $topic['topic_name'] }}</span>
                <span class="status-badge status-{{ $topic['status'] }}" style="float: right;">{{ $topic['status'] }}</span>
            </div>
            <div style="font-size: 14px; margin-bottom: 10px;">
                Score: {{ $topic['correct_answers'] }}/{{ $topic['total_questions'] }} ({{ $topic['percentage'] }}%)
            </div>
            <div class="recommendation">
                <strong>Recommendation:</strong> {{ $topic['recommendation'] }}
            </div>
        </div>
    @endforeach

    @if(count($report['weak_areas']) > 0)
        <div class="section-title">Key Areas for Improvement</div>
        <p style="font-size: 14px;">Focus your studies on these topics to improve your overall performance:</p>
        <ul>
            @foreach($report['weak_areas'] as $topic)
                <li style="font-size: 14px; margin-bottom: 5px;">
                    <strong>{{ $topic['topic_name'] }}:</strong> {{ $topic['recommendation'] }}
                </li>
            @endforeach
        </ul>
    @endif

    <div class="footer">
        © {{ date('Y') }} Modeh Assessment Platform. All rights reserved.
    </div>
</body>
</html>
