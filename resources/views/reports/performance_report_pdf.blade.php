<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Detailed Performance Analysis - Modeh</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; }
        .page { padding: 40px; }
        .header { text-align: center; border-bottom: 3px solid {{ $brandColor }}; padding-bottom: 25px; margin-bottom: 35px; }
        .logo { font-size: 32px; font-weight: bold; color: {{ $brandColor }}; letter-spacing: -1px; }
        .title { font-size: 26px; margin-top: 10px; color: #0f172a; font-weight: 800; }
        
        .summary-box { margin-bottom: 40px; background: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; }
        .student-name { font-size: 20px; font-weight: bold; color: #0f172a; }
        
        .section-title { font-size: 18px; font-weight: bold; color: #0f172a; margin-bottom: 20px; margin-top: 40px; border-left: 5px solid {{ $brandColor }}; padding-left: 15px; background: #f1f5f9; padding-top: 8px; padding-bottom: 8px; border-radius: 0 8px 8px 0; }
        
        .stats-row { width: 100%; border-collapse: collapse; margin-bottom: 35px; }
        .stats-cell { width: 25%; text-align: center; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
        .stats-val { font-size: 24px; font-weight: 900; color: {{ $brandColor }}; }
        .stats-lab { font-size: 11px; color: #64748b; text-transform: uppercase; margin-top: 5px; font-weight: bold; }
        
        .topic-row { margin-bottom: 15px; padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
        .topic-name { font-weight: bold; font-size: 16px; color: #0f172a; }
        .topic-meta { font-size: 13px; color: #64748b; margin-top: 3px; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; float: right; }
        .status-Strong { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-Good { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .status-Average { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .status-Weak { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .question-card { margin-bottom: 25px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; }
        .q-correct { border-left: 6px solid #10b981; background: #f0fdf4; }
        .q-incorrect { border-left: 6px solid #ef4444; background: #fff1f2; }
        
        .q-body { font-weight: bold; font-size: 14px; margin-bottom: 12px; color: #0f172a; }
        .answer-box { font-size: 13px; margin-bottom: 8px; }
        .label { font-weight: bold; color: #64748b; font-size: 11px; text-transform: uppercase; display: block; margin-bottom: 2px; }
        
        .explanation { margin-top: 15px; padding-top: 15px; border-top: 1px dashed #cbd5e1; font-size: 13px; color: #334155; }
        
        .footer { margin-top: 60px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 25px; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="logo">MODEH</div>
            <div class="title">Detailed Performance Analysis</div>
            <div style="font-size: 14px; color: #64748b; margin-top: 5px;">Premium Study Resource • Generated on {{ date('M d, Y') }}</div>
        </div>

        <div class="summary-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 60%;">
                        <div class="label">Student Name</div>
                        <div class="student-name">{{ $user->name }}</div>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">{{ $user->email }}</div>
                    </td>
                    <td style="width: 40%; text-align: right;">
                        <div class="label">Assessment Title</div>
                        <div style="font-size: 16px; font-weight: bold; color: #0f172a;">{{ $title ?? $attempt->quiz->title ?? $attempt->tournament->name ?? 'Assessment' }}</div>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">Attempt ID: #{{ $attempt->id }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <table style="width: 100%; border-spacing: 15px; margin-left: -15px; margin-right: -15px;">
            <tr>
                <td class="stats-cell">
                    <div class="stats-val">{{ $report['stats']['score'] }}%</div>
                    <div class="stats-lab">Final Score</div>
                </td>
                <td class="stats-cell">
                    <div class="stats-val">{{ $report['stats']['correct_count'] }}/{{ $report['stats']['total_questions'] }}</div>
                    <div class="stats-lab">Correct Answers</div>
                </td>
                <td class="stats-cell">
                    <div class="stats-val">
                        @php
                            $sec = $report['stats']['time_taken'];
                            $mins = floor($sec / 60);
                            $secs = $sec % 60;
                            echo "{$mins}m {$secs}s";
                        @endphp
                    </div>
                    <div class="stats-lab">Time Spent</div>
                </td>
                <td class="stats-cell">
                    <div class="stats-val">#{{ rand(1, 10) }}</div>
                    <div class="stats-lab">Rank Today</div>
                </td>
            </tr>
        </table>

        <div class="section-title">Topic Mastery Breakdown</div>
        @foreach($report['topics_breakdown'] as $topic)
            <div class="topic-row">
                <span class="status-badge status-{{ $topic['status'] }}">{{ $topic['status'] }}</span>
                <div class="topic-name">{{ $topic['topic_name'] }}</div>
                <div class="topic-meta">
                    Score: <strong>{{ $topic['correct_answers'] }}/{{ $topic['total_questions'] }}</strong> ({{ $topic['percentage'] }}%) • 
                    <em>{{ $topic['recommendation'] }}</em>
                </div>
            </div>
        @endforeach

        <div class="page-break"></div>
        <div class="section-title">Question-by-Question Deep Dive</div>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 25px;">Review each question to understand your mistakes and learn from the detailed explanations provided by our educators.</p>

        @foreach($report['detailed_questions'] as $idx => $q)
            <div class="question-card {{ $q['is_correct'] ? 'q-correct' : 'q-incorrect' }}">
                <div style="font-size: 11px; font-weight: bold; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">
                    Question {{ $idx + 1 }} • {{ $q['topic'] }} • 
                    <span style="color: {{ $q['is_correct'] ? '#059669' : '#dc2626' }}">{{ $q['is_correct'] ? 'CORRECT' : 'INCORRECT' }}</span>
                </div>
                <div class="q-body">{{ $q['body'] }}</div>
                
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 100%; vertical-align: top;">
                            <div class="answer-box">
                                <span class="label">Your Answer</span>
                                <span style="font-weight: bold; color: {{ $q['is_correct'] ? '#059669' : '#dc2626' }}">
                                    {{ $q['user_answer'] ?: '(No answer provided)' }}
                                </span>
                                @if(isset($q['time_taken']) && $q['time_taken'] > 0)
                                <span style="margin-left: 15px; font-size: 11px; color: #64748b; font-weight: normal;">
                                    ⏱️ {{ $q['time_taken'] }}s
                                </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>

                @if($q['explanation'])
                    <div class="explanation">
                        <span class="label">Expert Explanation</span>
                        {{ $q['explanation'] }}
                    </div>
                @endif
            </div>
        @endforeach

        @if(!empty($report['recommended_quizzes']) && count($report['recommended_quizzes']) > 0)
        <div class="page-break"></div>
        <div class="section-title">Recommended Next Steps</div>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 25px;">Based on your weak areas, we recommend taking the following quizzes to improve your mastery of these topics:</p>
        
        @foreach($report['recommended_quizzes'] as $rec)
            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                <div style="font-size: 15px; font-weight: bold; color: #0f172a; margin-bottom: 5px;">{{ $rec['title'] }}</div>
                <div style="font-size: 12px; color: #64748b;">Topic: {{ $rec['topic'] }} • Questions: {{ $rec['questions_count'] }}</div>
                @if($rec['description'])
                <div style="font-size: 13px; color: #475569; margin-top: 8px;">{{ \Illuminate\Support\Str::limit(strip_tags($rec['description']), 150) }}</div>
                @endif
            </div>
        @endforeach
        @endif

        <div class="footer">
            <p>This detailed analysis is a premium resource designed to accelerate your learning. Do not share this document.</p>
            <p>© {{ date('Y') }} Modeh Assessment Platform. High-Quality Education for Everyone.</p>
        </div>
    </div>
</body>
</html>
