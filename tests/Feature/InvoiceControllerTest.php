<?php

use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    // Create users with different roles
    $this->admin = User::factory()->create(['role' => 'admin', 'hourly_rate' => 50]);
    $this->manager = User::factory()->create(['role' => 'manager']);
    $this->developer = User::factory()->create(['role' => 'developer', 'hourly_rate' => 45]);
    $this->otherDeveloper = User::factory()->create(['role' => 'developer']);
    
    // Create a project assigned to manager and developer
    $this->project = Project::factory()->create([
        'manager_id' => $this->manager->id,
        'developer_id' => $this->developer->id,
    ]);
    
    // Create a timesheet for the developer with approved entries
    $this->timesheet = Timesheet::getOrCreateForWeek($this->developer->id);
    
    // Add approved time entries
    TimeEntry::create([
        'user_id' => $this->developer->id,
        'project_id' => $this->project->id,
        'timesheet_id' => $this->timesheet->id,
        'description' => 'Approved work',
        'started_at' => Carbon::now()->subHours(4),
        'ended_at' => Carbon::now()->subHours(2),
        'duration_minutes' => 120,
        'is_billable' => true,
        'status' => TimeEntry::STATUS_APPROVED,
        'approved_by' => $this->manager->id,
        'approved_at' => Carbon::now(),
    ]);
    
    // Create existing invoice
    $this->invoice = Invoice::create([
        'user_id' => $this->developer->id,
        'timesheet_id' => $this->timesheet->id,
        'invoice_number' => Invoice::generateInvoiceNumber(),
        'period_start' => $this->timesheet->week_start,
        'period_end' => $this->timesheet->week_end,
        'total_hours' => 2,
        'total_amount' => 90,
        'status' => Invoice::STATUS_PENDING,
    ]);
});

test('developer can view their own invoices', function () {
    $response = $this->actingAs($this->developer)->getJson('/api/v1/invoices');
    
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['data']]);
});

test('developer can view their own invoice details', function () {
    $response = $this->actingAs($this->developer)->getJson(
        "/api/v1/invoices/{$this->invoice->id}"
    );
    
    $response->assertOk();
});

test('developer cannot view another users invoice', function () {
    $response = $this->actingAs($this->otherDeveloper)->getJson(
        "/api/v1/invoices/{$this->invoice->id}"
    );
    
    $response->assertForbidden();
});

test('manager can view pending invoices', function () {
    $response = $this->actingAs($this->manager)->getJson('/api/v1/invoices/pending');
    
    $response->assertOk();
});

test('manager can approve pending invoice', function () {
    $response = $this->actingAs($this->manager)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/approve"
    );
    
    $response->assertOk();
    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(Invoice::STATUS_APPROVED);
});

test('manager can decline pending invoice', function () {
    $response = $this->actingAs($this->manager)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/decline"
    );
    
    $response->assertOk();
    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(Invoice::STATUS_DECLINED);
});

test('developer cannot approve invoices', function () {
    $response = $this->actingAs($this->developer)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/approve"
    );
    
    $response->assertForbidden();
});

test('only admin can mark invoice as paid', function () {
    // First approve the invoice
    $this->invoice->approve($this->manager->id);
    
    // Developer cannot mark as paid
    $response = $this->actingAs($this->developer)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/mark-paid"
    );
    $response->assertForbidden();
    
    // Manager cannot mark as paid
    $response = $this->actingAs($this->manager)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/mark-paid"
    );
    $response->assertForbidden();
    
    // Admin can mark as paid
    $response = $this->actingAs($this->admin)->postJson(
        "/api/v1/invoices/{$this->invoice->id}/mark-paid"
    );
    $response->assertOk();
    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(Invoice::STATUS_PAID);
});

test('invoice number generation is unique', function () {
    $number1 = Invoice::generateInvoiceNumber();
    
    // Create an invoice to increment counter
    Invoice::create([
        'user_id' => $this->admin->id,
        'invoice_number' => $number1,
        'period_start' => Carbon::now()->startOfWeek(),
        'period_end' => Carbon::now()->endOfWeek(),
        'total_hours' => 1,
        'total_amount' => 50,
        'status' => Invoice::STATUS_DRAFT,
    ]);
    
    $number2 = Invoice::generateInvoiceNumber();
    
    expect($number1)->not->toBe($number2);
});

test('admin can filter invoices by status', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/invoices?status=pending');
    
    $response->assertOk();
});

test('admin can filter invoices by user', function () {
    $response = $this->actingAs($this->admin)->getJson(
        "/api/v1/invoices?user_id={$this->developer->id}"
    );
    
    $response->assertOk();
});
