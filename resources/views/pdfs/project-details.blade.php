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
            font-size: 22px;
        }
        .meta {
            color: #666;
            font-size: 10px;
        }
        .section {
            margin-bottom: 20px;
        }
        .section h2 {
            color: #4f46e5;
            font-size: 14px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .info-grid {
            display: table;
            width: 100%;
        }
        .info-row {
            display: table-row;
        }
        .info-label {
            display: table-cell;
            width: 120px;
            font-weight: bold;
            padding: 5px 0;
            color: #555;
        }
        .info-value {
            display: table-cell;
            padding: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background-color: #6366f1;
            color: white;
            padding: 6px;
            text-align: left;
            font-size: 10px;
        }
        td {
            padding: 5px 6px;
            border-bottom: 1px solid #ddd;
            font-size: 10px;
        }
        .priority-urgent { color: #dc2626; font-weight: bold; }
        .priority-high { color: #d97706; }
        .status-online { color: #16a34a; }
        .status-offline { color: #dc2626; }
        .footer {
            position: fixed;
            bottom: 20px;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $data['project']->name }}</h1>
        <p class="meta">Project Report - Generated: {{ $generatedAt }}</p>
    </div>

    <div class="section">
        <h2>Project Information</h2>
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">ID:</span>
                <span class="info-value">{{ $data['project']->id }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">URL:</span>
                <span class="info-value">{{ $data['project']->url ?? 'N/A' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Health Status:</span>
                <span class="info-value status-{{ $data['project']->health_status }}">
                    {{ ucfirst($data['project']->health_status ?? 'Unknown') }}
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Security:</span>
                <span class="info-value">{{ ucfirst($data['project']->security_status ?? 'Unknown') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Manager:</span>
                <span class="info-value">{{ $data['project']->manager->name ?? 'Not assigned' }}</span>
            </div>
        </div>
    </div>

    @if($data['project']->developers->count() > 0)
    <div class="section">
        <h2>Developers ({{ $data['project']->developers->count() }})</h2>
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th></tr>
            </thead>
            <tbody>
                @foreach($data['project']->developers as $dev)
                <tr>
                    <td>{{ $dev->name }}</td>
                    <td>{{ $dev->email }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($data['project']->todos->count() > 0)
    <div class="section">
        <h2>Recent Todos ({{ $data['project']->todos->count() }})</h2>
        <table>
            <thead>
                <tr><th>Title</th><th>Priority</th><th>Status</th><th>Assigned</th></tr>
            </thead>
            <tbody>
                @foreach($data['project']->todos as $todo)
                <tr>
                    <td>{{ Str::limit($todo->title, 35) }}</td>
                    <td class="priority-{{ $todo->priority }}">{{ ucfirst($todo->priority) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $todo->status)) }}</td>
                    <td>{{ $todo->assignee->name ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="footer">
        LSM Platform - Project Details Report
    </div>
</body>
</html>
