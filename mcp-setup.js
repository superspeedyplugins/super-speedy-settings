jQuery(function($) {
    var $generate = $('#ssp-mcp-generate');
    var $revoke = $('#ssp-mcp-revoke');
    var $result = $('#ssp-mcp-result');
    var $error = $('#ssp-mcp-error');

    function showError(message) {
        $error.find('p').text(message || superspeedy_mcp.error);
        $error.prop('hidden', false);
    }

    $generate.on('click', function() {
        $error.prop('hidden', true);
        $generate.prop('disabled', true).text(superspeedy_mcp.generating);
        $.post(ajaxurl, {
            action: 'superspeedy_mcp_create_connection',
            nonce: superspeedy_mcp.nonce
        }).done(function(response) {
            if (!response.success) {
                showError(response.data && response.data.message);
                return;
            }
            $('#ssp-mcp-codex').val(response.data.codex);
            $('#ssp-mcp-claude').val(response.data.claude);
            $('#ssp-mcp-message').text(response.data.message);
            $result.prop('hidden', false);
            $revoke.prop('disabled', false);
        }).fail(function(xhr) {
            showError(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message);
        }).always(function() {
            $generate.prop('disabled', false).text(superspeedy_mcp.replace);
        });
    });

    $revoke.on('click', function() {
        $error.prop('hidden', true);
        $revoke.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'superspeedy_mcp_revoke_connection',
            nonce: superspeedy_mcp.nonce
        }).done(function(response) {
            if (!response.success) {
                showError(response.data && response.data.message);
                $revoke.prop('disabled', false);
                return;
            }
            $result.prop('hidden', true);
            $generate.text(superspeedy_mcp.generate);
        }).fail(function(xhr) {
            showError(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message);
            $revoke.prop('disabled', false);
        });
    });

    $(document).on('click', '.ssp-mcp-copy', function() {
        var button = this;
        var field = document.getElementById($(button).data('target'));
        navigator.clipboard.writeText(field.value).then(function() {
            var original = button.textContent;
            button.textContent = superspeedy_mcp.copied;
            window.setTimeout(function() { button.textContent = original; }, 1600);
        });
    });
});
