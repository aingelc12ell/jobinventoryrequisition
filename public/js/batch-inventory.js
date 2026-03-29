/**
 * Batch Inventory Operations — dynamic rows, auto-search, duplicate warnings.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var SEARCH_URL = '/api/inventory/search';
  var DEBOUNCE_MS = 300;

  // ══════════════════════════════════════════════════════════════════
  // Utility helpers
  // ══════════════════════════════════════════════════════════════════

  function debounce(fn, ms) {
    var timer;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function renumberRows(tbody) {
    var rows = tbody.querySelectorAll('tr');
    rows.forEach(function (tr, i) {
      var numCell = tr.querySelector('.row-number');
      if (numCell) numCell.textContent = i + 1;
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // SECTION 1 — Batch Stock In
  // ══════════════════════════════════════════════════════════════════

  var stockInRows = document.getElementById('stockInRows');
  var addStockInBtn = document.getElementById('addStockInRow');
  var clearStockInBtn = document.getElementById('clearStockIn');
  var stockInRowIndex = 0;

  function createStockInRow() {
    var idx = stockInRowIndex++;
    var tr = document.createElement('tr');
    tr.setAttribute('data-row-index', idx);
    tr.innerHTML =
      '<td class="row-number text-center"></td>' +
      '<td>' +
        '<div class="position-relative">' +
          '<input type="text" class="form-control form-control-sm stock-in-search" ' +
                 'placeholder="Type to search..." autocomplete="off" data-idx="' + idx + '">' +
          '<input type="hidden" name="items[' + idx + '][item_id]" class="stock-in-item-id">' +
          '<div class="dropdown-menu stock-in-dropdown w-100" style="max-height:240px;overflow-y:auto;"></div>' +
          '<div class="stock-in-selected small text-success mt-1" style="display:none;"></div>' +
        '</div>' +
      '</td>' +
      '<td class="stock-in-current text-center text-muted">&mdash;</td>' +
      '<td>' +
        '<input type="number" class="form-control form-control-sm" name="items[' + idx + '][quantity]" min="1" placeholder="0">' +
      '</td>' +
      '<td>' +
        '<input type="text" class="form-control form-control-sm" name="items[' + idx + '][notes]" placeholder="Optional notes">' +
      '</td>' +
      '<td class="text-center">' +
        '<button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove row">' +
          '<i class="fas fa-times"></i>' +
        '</button>' +
      '</td>';

    stockInRows.appendChild(tr);
    renumberRows(stockInRows);
    bindStockInSearch(tr, idx);
    bindRemoveRow(tr, stockInRows);

    return tr;
  }

  function bindStockInSearch(tr, idx) {
    var input = tr.querySelector('.stock-in-search');
    var dropdown = tr.querySelector('.stock-in-dropdown');
    var hiddenId = tr.querySelector('.stock-in-item-id');
    var currentCell = tr.querySelector('.stock-in-current');
    var selectedDiv = tr.querySelector('.stock-in-selected');

    var search = debounce(function () {
      var q = input.value.trim();
      if (q.length < 1) {
        dropdown.classList.remove('show');
        return;
      }

      fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (items) {
          dropdown.innerHTML = '';
          if (!items.length) {
            dropdown.innerHTML = '<div class="dropdown-item text-muted small">No items found</div>';
            dropdown.classList.add('show');
            return;
          }
          items.forEach(function (item) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'dropdown-item';
            el.innerHTML =
              '<div class="fw-semibold">' + escapeHtml(item.name) + '</div>' +
              '<small class="text-muted">' +
                (item.sku ? 'SKU: ' + escapeHtml(item.sku) + ' | ' : '') +
                (item.category ? escapeHtml(item.category) + ' | ' : '') +
                'Stock: ' + item.quantity_in_stock + ' ' + escapeHtml(item.unit) +
              '</small>';
            el.addEventListener('click', function () {
              selectStockInItem(item, input, hiddenId, currentCell, selectedDiv, dropdown);
            });
            dropdown.appendChild(el);
          });
          dropdown.classList.add('show');
        })
        .catch(function () { dropdown.classList.remove('show'); });
    }, DEBOUNCE_MS);

    input.addEventListener('input', search);
    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 1 && dropdown.children.length) {
        dropdown.classList.add('show');
      }
    });

    // Close dropdown on outside click
    document.addEventListener('click', function (e) {
      if (!tr.contains(e.target)) {
        dropdown.classList.remove('show');
      }
    });
  }

  function selectStockInItem(item, input, hiddenId, currentCell, selectedDiv, dropdown) {
    hiddenId.value = item.id;
    input.value = item.name + (item.sku ? ' (' + item.sku + ')' : '');
    input.readOnly = true;
    input.classList.add('bg-light');
    currentCell.innerHTML = '<span class="fw-semibold">' + item.quantity_in_stock + '</span> ' + escapeHtml(item.unit);
    selectedDiv.innerHTML =
      '<i class="fas fa-check-circle me-1"></i>' + escapeHtml(item.name) +
      ' <a href="#" class="ms-2 text-danger stock-in-clear" title="Clear selection"><i class="fas fa-times-circle"></i></a>';
    selectedDiv.style.display = 'block';
    dropdown.classList.remove('show');

    // Bind clear
    var clearBtn = selectedDiv.querySelector('.stock-in-clear');
    clearBtn.addEventListener('click', function (e) {
      e.preventDefault();
      hiddenId.value = '';
      input.value = '';
      input.readOnly = false;
      input.classList.remove('bg-light');
      currentCell.innerHTML = '&mdash;';
      selectedDiv.style.display = 'none';
      input.focus();
    });
  }

  if (addStockInBtn) {
    addStockInBtn.addEventListener('click', function () { createStockInRow(); });
    // Start with 3 rows
    createStockInRow();
    createStockInRow();
    createStockInRow();
  }

  if (clearStockInBtn) {
    clearStockInBtn.addEventListener('click', function () {
      stockInRows.innerHTML = '';
      stockInRowIndex = 0;
      createStockInRow();
      createStockInRow();
      createStockInRow();
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // SECTION 2 — Batch Add New Items
  // ══════════════════════════════════════════════════════════════════

  var addItemRows = document.getElementById('addItemRows');
  var addNewItemBtn = document.getElementById('addNewItemRow');
  var clearAddBtn = document.getElementById('clearAddItems');
  var addItemRowIndex = 0;
  var duplicateWarnings = document.getElementById('duplicateWarnings');
  var duplicateList = document.getElementById('duplicateList');

  function createAddItemRow() {
    var idx = addItemRowIndex++;
    var tr = document.createElement('tr');
    tr.setAttribute('data-row-index', idx);
    tr.innerHTML =
      '<td class="row-number text-center"></td>' +
      '<td>' +
        '<div class="position-relative">' +
          '<input type="text" class="form-control form-control-sm add-item-name" ' +
                 'name="items[' + idx + '][name]" required autocomplete="off" ' +
                 'placeholder="Item name" data-idx="' + idx + '">' +
          '<div class="dropdown-menu add-item-dropdown w-100" style="max-height:200px;overflow-y:auto;"></div>' +
        '</div>' +
      '</td>' +
      '<td>' +
        '<div class="position-relative">' +
          '<input type="text" class="form-control form-control-sm add-item-sku" ' +
                 'name="items[' + idx + '][sku]" autocomplete="off" placeholder="SKU" data-idx="' + idx + '">' +
          '<div class="dropdown-menu add-sku-dropdown w-100" style="max-height:200px;overflow-y:auto;"></div>' +
        '</div>' +
      '</td>' +
      '<td>' +
        '<input type="text" class="form-control form-control-sm" ' +
               'name="items[' + idx + '][category]" list="batch-category-list" placeholder="Category">' +
      '</td>' +
      '<td>' +
        '<input type="text" class="form-control form-control-sm" ' +
               'name="items[' + idx + '][unit]" required value="pcs" placeholder="pcs">' +
      '</td>' +
      '<td>' +
        '<input type="number" class="form-control form-control-sm" ' +
               'name="items[' + idx + '][quantity_in_stock]" min="0" value="0">' +
      '</td>' +
      '<td>' +
        '<input type="number" class="form-control form-control-sm" ' +
               'name="items[' + idx + '][reorder_level]" min="0" value="0">' +
      '</td>' +
      '<td>' +
        '<input type="text" class="form-control form-control-sm" ' +
               'name="items[' + idx + '][location]" placeholder="Location">' +
      '</td>' +
      '<td class="text-center">' +
        '<button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove row">' +
          '<i class="fas fa-times"></i>' +
        '</button>' +
      '</td>';

    addItemRows.appendChild(tr);
    renumberRows(addItemRows);
    bindAddItemSearch(tr, idx);
    bindRemoveRow(tr, addItemRows);

    return tr;
  }

  function bindAddItemSearch(tr, idx) {
    var nameInput = tr.querySelector('.add-item-name');
    var nameDropdown = tr.querySelector('.add-item-dropdown');
    var skuInput = tr.querySelector('.add-item-sku');
    var skuDropdown = tr.querySelector('.add-sku-dropdown');

    // Search by name
    var searchByName = debounce(function () {
      searchForDuplicates(nameInput.value.trim(), nameDropdown, tr);
    }, DEBOUNCE_MS);

    nameInput.addEventListener('input', searchByName);
    nameInput.addEventListener('focus', function () {
      if (nameInput.value.trim().length >= 2 && nameDropdown.children.length) {
        nameDropdown.classList.add('show');
      }
    });

    // Search by SKU
    var searchBySku = debounce(function () {
      searchForDuplicates(skuInput.value.trim(), skuDropdown, tr);
    }, DEBOUNCE_MS);

    skuInput.addEventListener('input', searchBySku);
    skuInput.addEventListener('focus', function () {
      if (skuInput.value.trim().length >= 1 && skuDropdown.children.length) {
        skuDropdown.classList.add('show');
      }
    });

    // Close dropdowns on outside click
    document.addEventListener('click', function (e) {
      if (!tr.contains(e.target)) {
        nameDropdown.classList.remove('show');
        skuDropdown.classList.remove('show');
      }
    });
  }

  function searchForDuplicates(q, dropdown, contextRow) {
    if (q.length < 2) {
      dropdown.classList.remove('show');
      return;
    }

    fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (items) {
        dropdown.innerHTML = '';
        if (!items.length) {
          dropdown.classList.remove('show');
          return;
        }

        var header = document.createElement('div');
        header.className = 'dropdown-header small text-warning';
        header.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i> Similar items already exist:';
        dropdown.appendChild(header);

        items.forEach(function (item) {
          var el = document.createElement('div');
          el.className = 'dropdown-item small';
          el.style.cursor = 'default';
          el.innerHTML =
            '<div class="fw-semibold">' + escapeHtml(item.name) + '</div>' +
            '<span class="text-muted">' +
              (item.sku ? 'SKU: ' + escapeHtml(item.sku) + ' | ' : '') +
              (item.category ? escapeHtml(item.category) + ' | ' : '') +
              'Stock: ' + item.quantity_in_stock + ' ' + escapeHtml(item.unit) +
            '</span>';
          dropdown.appendChild(el);
        });
        dropdown.classList.add('show');
      })
      .catch(function () { dropdown.classList.remove('show'); });
  }

  if (addNewItemBtn) {
    addNewItemBtn.addEventListener('click', function () { createAddItemRow(); });
    // Start with 3 rows
    createAddItemRow();
    createAddItemRow();
    createAddItemRow();
  }

  if (clearAddBtn) {
    clearAddBtn.addEventListener('click', function () {
      addItemRows.innerHTML = '';
      addItemRowIndex = 0;
      createAddItemRow();
      createAddItemRow();
      createAddItemRow();
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // Shared: Remove row
  // ══════════════════════════════════════════════════════════════════

  function bindRemoveRow(tr, tbody) {
    var btn = tr.querySelector('.remove-row');
    btn.addEventListener('click', function () {
      // Keep at least 1 row
      if (tbody.querySelectorAll('tr').length <= 1) return;
      tr.remove();
      renumberRows(tbody);
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // Form validation before submit
  // ══════════════════════════════════════════════════════════════════

  var stockInForm = document.getElementById('batchStockInForm');
  if (stockInForm) {
    stockInForm.addEventListener('submit', function (e) {
      var hasValid = false;
      var rows = stockInRows.querySelectorAll('tr');
      rows.forEach(function (tr) {
        var itemId = tr.querySelector('.stock-in-item-id');
        var qty = tr.querySelector('input[type="number"]');
        if (itemId && itemId.value && qty && parseInt(qty.value, 10) > 0) {
          hasValid = true;
        }
      });

      if (!hasValid) {
        e.preventDefault();
        if (window.JIR && window.JIR.toast) {
          window.JIR.toast('Please select at least one item and enter a quantity.', 'warning');
        } else {
          alert('Please select at least one item and enter a quantity.');
        }
      }
    });
  }

  var addForm = document.getElementById('batchAddForm');
  if (addForm) {
    addForm.addEventListener('submit', function (e) {
      var hasValid = false;
      var rows = addItemRows.querySelectorAll('tr');
      rows.forEach(function (tr) {
        var nameField = tr.querySelector('.add-item-name');
        if (nameField && nameField.value.trim().length >= 2) {
          hasValid = true;
        }
      });

      if (!hasValid) {
        e.preventDefault();
        if (window.JIR && window.JIR.toast) {
          window.JIR.toast('Please fill in at least one item with a valid name (2+ characters).', 'warning');
        } else {
          alert('Please fill in at least one item with a valid name.');
        }
      }
    });
  }
});
