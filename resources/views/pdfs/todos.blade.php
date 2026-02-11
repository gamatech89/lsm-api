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
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .priority-urgent { background: #fee2e2; color: #dc2626; font-weight: bold; }
        .priority-high { background: #fef3c7; color: #d97706; }
        .priority-medium { background: #fef9c3; color: #ca8a04; }
        .priority-low { background: #dcfce7; color: #16a34a; }
        .status-pending { color: #6b7280; }
        .status-in_progress { color: #2563eb; }
        .status-completed { color: #16a34a; }
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
        <h1>{{ $title }}</h1>
        <p class="meta">Generated: {{ $generatedAt }} by {{ $generatedBy }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Project</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Assigned To</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['todos'] as $todo)
            <tr>
                <td>{{ $todo->id }}</td>
                <td>{{ Str::limit($todo->title, 40) }}</td>
                <td>{{ $todo->project->name ?? '-' }}</td>
                <td class="priority-{{ $todo->priority }}">{{ ucfirst($todo->priority) }}</td>
                <td class="status-{{ $todo->status }}">{{ ucfirst(str_replace('_', ' ', $todo->status)) }}</td>
                <td>{{ $todo->assignee->name ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        LSM Platform - Todos Report
    </div>
</body>
</html>
