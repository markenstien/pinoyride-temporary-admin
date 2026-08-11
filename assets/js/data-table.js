// Auto-initializes DataTables (https://datatables.net/) on every
// <table class="dataTable">. Include jQuery + DataTables (core and the
// bootstrap5 styling addon) before this file, then just add the class to
// any table's markup — no per-page init script needed.
//
// Add data-paging="false" on tables that already have their own
// server-side pagination (the list pages with a Filter form and a
// Previous/Next nav) so DataTables only adds client-side sort + a quick
// filter over the rows already on the page, instead of a second,
// conflicting pager.
//
// The last column is auto-detected as non-sortable whenever its header
// cell is empty — that's always a row-actions column of buttons, never
// sortable data.
$(function () {
  $('table.dataTable').each(function () {
    var $table = $(this);
    var paging = $table.data('paging') !== false;

    var columnDefs = [];
    var $lastHeader = $table.find('thead th').last();
    if ($lastHeader.length && $lastHeader.text().trim() === '') {
      columnDefs.push({ orderable: false, targets: -1 });
    }

    $table.DataTable({
      paging: paging,
      info: paging,
      order: [],
      columnDefs: columnDefs,
    });
  });
});
