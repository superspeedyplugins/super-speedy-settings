
jQuery(document).ready(function($) {
    $('#recheck-license').on('click', function(e) {
        console.log('recheck clicked');
        e.preventDefault();
        
        // Get the current license key value from input field
        var licenseKey = $('#superspeedy_field_licensekey').val();
        
        // Create data object for AJAX request
        var data = {
            'action': 'superspeedy_recheck_license',
            'nonce': superspeedy_ajax.nonce,
            'license_key': licenseKey
        };

        // Disable button and show loading state
        var $button = $(this);
        $button.prop('disabled', true);
        $button.text('Checking...');

        // Make AJAX call
        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Reload the page to show updated status
                window.location.reload();
            } else {
                alert('Failed to recheck license. Please try again.');
                $button.prop('disabled', false);
                $button.text('Recheck now');
            }
        }).fail(function() {
            alert('Failed to recheck license. Please try again.');
            $button.prop('disabled', false);
            $button.text('Recheck now');
        });
    });
});