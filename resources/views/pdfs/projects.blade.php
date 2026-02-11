<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #6366f1;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #6366f1;
            margin: 0 0 5px 0;
            font-size: 24px;
        }
        .meta {
            color: #666;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th {
            background-color: #6366f1;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            font-size: 10px;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .status-online { color: #16a34a; }
        .status-offline { color: #dc2626; }
        .status-maintenance { color: #ca8a04; }
        .security-secure { color: #16a34a; }
        .security-at_risk { color: #ca8a04; }
        .security-compromised, .security-hacked { color: #dc2626; }
        .footer {
            position: fixed;
            bottom: 20px;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #999;
        }
        .summary {
            background: #f3f4f6;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <p class="meta">Generated: {{ $generatedAt }} by {{ $generatedBy }}</p>
    </div>

    <div class="summary">
        <strong>Total Projects:</strong> {{ $data['projects']->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>URL</th>
                <th>Health</th>
                <th>Security</th>
                <th>Manager</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['projects'] as $project)
            <tr>
                <td>{{ $project->id }}</td>
                <td>{{ $project->name }}</td>
                <td style="font-size: 9px;">{{ Str::limit($project->url, 30) }}</td>
                <td class="status-{{ $project->health_status }}">{{ ucfirst($project->health_status ?? 'unknown') }}</td>
                <td class="security-{{ $project->security_status }}">{{ ucfirst($project->security_status ?? 'unknown') }}</td>
                <td>{{ $project->manager->name ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        LSM Platform - Projects Report
    </div>
</body>
</html>
