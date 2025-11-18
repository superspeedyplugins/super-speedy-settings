jQuery(document).ready(function($) {
    // Function to create and attach a MutationObserver to a specific table
    function attachObserverToTable(tableSelector) {
        var table = $(tableSelector);
        if (table.length === 0) {
            return; // If the table isn't found, exit the function
        }

        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                Array.from(mutation.addedNodes).concat(mutation.target ? [mutation.target] : []).forEach(function(node) {
                    var $node = $(node);
                    if ($node.find('.update-message.notice-error').length > 0 || $node.hasClass('update-message notice-error')) {
                        console.log('triggered');
                        var current_text = $node.find('.update-message.notice-error').html();
                        if (current_text.includes('Error Code: exceeded')) {
                            $node.find('.update-message.notice-error').html('<p>You have exceeded your licenses...</p>');
                        } else if (current_text.includes('Error Code: expired')) {
                            $node.find('.update-message.notice-error').html('<p>Your license has expired...</p>');
                        } else if (current_text.includes('Error Code: Not Purchased')) {
                            $node.find('.update-message.notice-error').html('<p>You have not purchased this plugin...</p>');
                        } else if (current_text.includes('Error Code: invalid')) {
                            $node.find('.update-message.notice-error').html('<p>You need to enter a valid license key...</p>');
                        }
                    }
                });
            });
        });

        var config = { childList: true, subtree: true, attributes: true, characterData: true };
        observer.observe(table.get(0), config);
    }

    // Attach observers to both tables
    attachObserverToTable('#update-plugins-table');
    attachObserverToTable('table.wp-list-table.plugins');
});
