
jQuery(document).ready(function($) {
    var $keyInput = $('#superspeedy_field_licensekey');
    var $topBtn   = $('#recheck-licenses-top');
    var $topHint  = $('.recheck-licenses-hint');

    function syncTopButtonState() {
        var hasKey = !!($keyInput.val() || '').trim();
        $topBtn.prop('disabled', !hasKey);
        $topHint.toggle(!hasKey);
    }
    $keyInput.on('input change', syncTopButtonState);

    $('#recheck-license, #recheck-licenses-top').on('click', function(e) {
        console.log('recheck clicked');
        e.preventDefault();

        var $button      = $(this);
        var originalText = $button.text();
        var licenseKey   = $keyInput.val();

        var data = {
            'action':      'superspeedy_recheck_license',
            'nonce':       superspeedy_ajax.nonce,
            'license_key': licenseKey
        };

        $button.prop('disabled', true);
        $button.text('Checking...');

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert('Failed to recheck license. Please try again.');
                $button.prop('disabled', false);
                $button.text(originalText);
            }
        }).fail(function() {
            alert('Failed to recheck license. Please try again.');
            $button.prop('disabled', false);
            $button.text(originalText);
        });
    });
});

jQuery(document).ready(function($) {
    // Install Performance Analysis from the settings panel. The button is only
    // rendered for a user who can install plugins; the handler checks again.
    $('#superspeedy-install-sspa').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $status = $('.superspeedy-sspa-status');
        var original = $button.text();

        $button.prop('disabled', true).text('Installing...');
        $status.text('');

        $.post(ajaxurl, {
            action: 'superspeedy_install_performance_analysis',
            nonce: $button.data('nonce')
        }).done(function(response) {
            if (response && response.success) {
                $status.text(response.data.message || 'Installed.');
                if (response.data && response.data.redirect) {
                    window.location = response.data.redirect;
                }
                return;
            }
            $button.prop('disabled', false).text(original);
            $status.text((response && response.data && response.data.message) || 'The install failed.');
        }).fail(function(xhr) {
            var message = 'The install failed.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }
            $button.prop('disabled', false).text(original);
            $status.text(message);
        });
    });
});
