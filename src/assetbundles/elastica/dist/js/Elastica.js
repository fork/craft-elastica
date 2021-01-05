/**
 * Elasticsearch plugin for Craft CMS
 *
 * Elasticsearch JS
 *
 * @author    Fork Unstable Media GmbH
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 * @link      http://fork.de
 * @package   Elastica
 * @since     1.0.0
 */

(function($) {

    $(document).ready(function() {
        $('#settings-elasticsearch-reindex').each(function() {
            $(this).click(function() {
                if (!confirm('Are you sure?')) {
                    return;
                }
                var $spinner = $(this).parent().find('.spinner');
                $spinner.removeClass('hidden');
                Craft.postActionRequest('elasticsearch/elasticsearch/reindex', null, function(response) {
                    if (response) {
                        $spinner.addClass('hidden');
                        Craft.cp.displayNotice('Finished re-indexing!');
                    }
                });
            });
        });
    });

})(jQuery);
