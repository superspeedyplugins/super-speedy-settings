
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
