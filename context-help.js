var ssp_context_sensitive_help = [
    {
        css_selector: 'table.superspeedy-plugins th:nth-child(3)',
        message:  'If you exceed your licenses or your license is expired, you will not be able to download updates. Please remember we are a small team and try to support us by buying a relevant license.',
        full_guide_url: '',
        use_white: true
    },
    {
        css_selector: 'table.superspeedy-plugins th:nth-child(4)',
        message:  'If one of our plugins is active, we will highlight in the wp-admin > Super Speedy menu a number showing you how many updates are available to you.',
        full_guide_url: '',
        use_white: true
    },
    {
        css_selector: 'table.superspeedy-plugins th:nth-child(6)',
        message:  'Please check our knowledgebase first prior to asking for help in our live channels. We have a lot of information there and it is likely your question has already been answered.',
        full_guide_url: '',
        use_white: true
    }

];

/* loop through each item in ssp_context_sensitive_help and add the tooltip.

1. Check if there is already a ssp_input_help_container parent span parent, if not then wrap it in one
2. After this element, inside the .ssp_input_help_container, add a button with this format:

<button class="ssp_help_tooltip show_tooltip" aria-describedby="tooltip"><span class="dashicons dashicons-editor-help"></span><span class="ssp_help_tooltip_container"><span class="ssp_help_tooltip_content">You can hide filters from your users until they are ready. Useful when experimenting or for initial setup.</span>
    <a class="ssp_help" role="help" href="https://www.wpintense.com/knowledgebase/enabled/" target="_blank">Read full guide</a>    <span class="ssp_arrow"></span></span></button>

3. Set the content of .ssp_help_tooltip_content with ssp_context_sensitive_help.message
4. Set the content of the a.ssp_help href with ssp_context_sensitive_help.full_guide_url
*/

jQuery(document).ready(function() {
    for (var i = 0; i < ssp_context_sensitive_help.length; i++) {
        var help = ssp_context_sensitive_help[i];
        var message = help.message;
        var full_guide_url = help.full_guide_url;

        var element = jQuery(help.css_selector);
        if (element.length > 0) {
            color_class = '';
            if (help.use_white) {
                color_class = 'white';
            }
            full_guide_link = '';
            if (full_guide_url.length > 0) {
                full_guide_link = '<a class="ssp_help" role="help" href="' + full_guide_url + '" target="_blank">Read full guide</a>';
            }
            help_button = element.append('<button class="ssp_help_tooltip ' + color_class + '" aria-describedby="tooltip"><span class="dashicons dashicons-editor-help"></span><span class="ssp_help_tooltip_container"><span class="ssp_help_tooltip_content"></span>' + full_guide_link + '<span class="ssp_arrow"></span></span></button>');
            help_button.find('.ssp_help_tooltip_content').html(message);
        }
    }
});

jQuery(document).on('click', 'button.ssp_help_tooltip', function(e) {

    jQuery('.ssp_help_tooltip').not(this).removeClass('show_tooltip');

    jQuery(this).toggleClass('show_tooltip');

    button = jQuery(this);
    tooltip = jQuery('.ssp_help_tooltip_content', this);
    arrow = jQuery('.ssp_arrow', this);    
    e.preventDefault();
    e.stopPropagation();
    return false;
});

jQuery(document).on('click', '.ssp_help_tooltip .ssp_help', function() {
    console.log(jQuery(this).attr('class'));
    e.stopPropagation();
});
jQuery(document).click(function() {
    jQuery('.ssp_help_tooltip').removeClass('show_tooltip');
});
