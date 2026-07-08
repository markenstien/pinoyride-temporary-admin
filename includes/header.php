<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Search</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f4f6f9; }
  .navbar-brand { font-weight: 600; }
  .filter-card { border: none; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
  .table thead th { white-space: nowrap; background: #eef1f5; }
  .badge-status-1 { background-color: #198754; }
  .badge-status-0 { background-color: #6c757d; }
  .table-responsive { border-radius: .5rem; overflow: hidden; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">RiderApp Admin</span>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-sm <?= $activeNav === 'customers' ? 'btn-light' : 'btn-outline-light' ?>">Customers</a>
      <a href="riders.php" class="btn btn-sm <?= $activeNav === 'riders' ? 'btn-light' : 'btn-outline-light' ?>">Riders</a>
      <a href="bookings.php" class="btn btn-sm <?= $activeNav === 'bookings' ? 'btn-light' : 'btn-outline-light' ?>">Bookings</a>
    </div>
  </div>
</nav>
<div class="container-fluid px-4">
