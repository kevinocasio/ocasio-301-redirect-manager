jQuery(document).ready(function ($) {
    // --- INLINE EDIT LOGIC ---
    var $form = $('#ocasio-redirect-form');
    var $pathInput = $form.find('input[name="ocasio_301_request_path"]');
    var $destInput = $form.find('input[name="ocasio_301_destination_url"]');
    var $submitBtn = $('#ocasio-save-btn');
    var $editBanner = null;
    var $hiddenOriginalPath = null;

    // Handle Edit Button Click (Inline)
    $(document).on('click', '.ko-btn-edit', function (e) {
        e.preventDefault();

        var $row = $(this).closest('tr');
        var path = $row.data('path');
        var dest = $row.data('dest');

        // Populate form
        $pathInput.val(path);
        $destInput.val(dest);

        // Change button text
        $submitBtn.text('Update Redirect');

        // Add hidden field for original path
        if (!$hiddenOriginalPath) {
            $hiddenOriginalPath = $('<input type="hidden" name="ocasio_301_original_path">');
            $form.prepend($hiddenOriginalPath);
        }
        $hiddenOriginalPath.val(btoa(path));

        // Create edit banner if not exists
        if (!$editBanner) {
            $editBanner = $('<div class="ko-edit-banner" style="display:none; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 13px; font-weight: 500;"><strong>Editing:</strong> <code class="edit-path" style="background:none; color:#1e40af; font-weight:700;"></code> <a href="#" class="ko-cancel-edit" style="margin-left: 12px; color: #e11d48; font-weight: 600; text-decoration: none;">Cancel</a></div>');
            $form.find('[name="ocasio_301_nonce"]').after($editBanner);
        }
        $editBanner.find('.edit-path').text(path);

        // Smooth scroll to form
        $('html, body').animate({ scrollTop: $form.offset().top - 120 }, 300, function () {
            $editBanner.slideDown(150);
            $destInput.focus();
        });
    });

    // Handle Cancel Click (Inline)
    $(document).on('click', '.ko-cancel-edit', function (e) {
        e.preventDefault();

        // Clear form
        $pathInput.val('');
        $destInput.val('');

        // Reset button text
        $submitBtn.text('Add Redirect');

        // Remove hidden field and banner
        if ($hiddenOriginalPath) {
            $hiddenOriginalPath.remove();
            $hiddenOriginalPath = null;
        }
        if ($editBanner) {
            $editBanner.slideUp(150);
        }
    });

    // --- 2-BUTTON IN-PLACE ACTION SWAP (Zero Layout Shift) ---
    $(document).on('click', '.ko-btn-trash', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.ko-action-cell-wrap');

        // Reset all other active confirm states across the table
        $('.ko-action-state-confirm').hide();
        $('.ko-action-state-default').css('display', 'inline-flex');

        // Swap this cell to confirm state
        $wrap.find('.ko-action-state-default').hide();
        $wrap.find('.ko-action-state-confirm').css('display', 'inline-flex');
    });

    // Click Cancel X
    $(document).on('click', '.ko-btn-confirm-no', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.ko-action-cell-wrap');
        $wrap.find('.ko-action-state-confirm').hide();
        $wrap.find('.ko-action-state-default').css('display', 'inline-flex');
    });

    // Click Confirm Yes (AJAX Delete with smooth fadeout)
    $(document).on('click', '.ko-btn-confirm-yes', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var path = $row.data('path');

        $btn.css('opacity', '0.5');

        $.ajax({
            url: ocasio_301_vars.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ocasio_301_delete_redirect',
                path: path,
                nonce: ocasio_301_vars.nonce
            },
            success: function (res) {
                if (res.success) {
                    $row.fadeOut(200, function () {
                        $(this).remove();
                        // Update total counter
                        if ($('#ko-total-count').length) {
                            $('#ko-total-count').text(res.data.total);
                        }
                        // If no more rows exist, show empty state container
                        if ($('.ko-table-container tbody tr').length === 0) {
                            $('.ko-table-container').html('<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:22px; text-align:center; color:#94a3b8; font-size:13px;">No active redirects yet. Add your first redirect rule above.</div>');
                        }
                    });
                } else {
                    $btn.css('opacity', '1');
                }
            },
            error: function () {
                $btn.css('opacity', '1');
            }
        });
    });

    // Auto-revert when clicking anywhere outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.ko-action-cell-wrap').length) {
            $('.ko-action-state-confirm').hide();
            $('.ko-action-state-default').css('display', 'inline-flex');
        }
    });

    // --- AUTOCOMPLETE ---
    var $input = $('input[name="ocasio_301_destination_url"]');

    if ($input.length && typeof $.fn.autocomplete !== 'undefined') {
        $input.autocomplete({
            minLength: 2,
            source: function (request, response) {
                var term = request.term.trim();
                // Suppress search if user is typing a direct URL or path
                if (/^(https?:\/\/|\/\/|\/|www\.)/i.test(term) || term.indexOf('.') !== -1) {
                    response([]);
                    return;
                }

                $.ajax({
                    url: ocasio_301_vars.ajaxurl,
                    dataType: "json",
                    data: {
                        action: 'ocasio_301_search_content',
                        term: term,
                        nonce: ocasio_301_vars.nonce
                    },
                    success: function (data) {
                        response(data);
                    }
                });
            },
            select: function (event, ui) {
                $input.val(ui.item.value);
                return false;
            },
            focus: function (event, ui) {
                return false;
            }
        });

        if ($input.autocomplete("widget")) {
            $input.autocomplete("widget").addClass("ko-301-autocomplete");
        }
    }

    // --- BUTTON STATE REVERT ---
    const btn = document.getElementById('ocasio-save-btn');
    if (btn && btn.classList.contains('ko-btn-saved')) {
        setTimeout(() => {
            btn.classList.remove('ko-btn-saved');
            btn.innerText = 'Add Redirect';
            window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[\?&]updated=true/, ''));
        }, 2000);
    }

    // --- SUITE DASHBOARD AJAX TOGGLE ---
    $(document).on('change', '.ko-ajax-toggle', function () {
        var optName = $(this).data('option');
        var slug = $(this).data('slug');
        var isChecked = this.checked ? 1 : 0;
        var $savedPill = $('#saved-' + slug);
        var $badge = $('#badge-' + slug);

        if ($badge.length) {
            if (isChecked) {
                $badge.attr('class', 'ko-dash-badge badge-active').text('Active');
            } else {
                $badge.attr('class', 'ko-dash-badge badge-paused').text('Paused');
            }
        }

        $.ajax({
            url: ocasio_301_vars.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ocasio_suite_save_toggle',
                option_name: optName,
                option_value: isChecked,
                nonce: ocasio_301_vars.suite_nonce
            },
            success: function (res) {
                if (res.success && $savedPill.length) {
                    $savedPill.show();
                    setTimeout(function () {
                        $savedPill.fadeOut(200);
                    }, 1800);
                }
            },
            error: function (err) {
                console.error('Toggle save failed:', err);
            }
        });
    });
});
