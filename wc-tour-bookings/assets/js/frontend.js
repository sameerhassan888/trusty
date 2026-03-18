jQuery(document).ready(function($) {
    if ($('.tour-booking-date').length > 0) {
        $('.tour-booking-date').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0
        });
    }

    function updateParticipantFields() {
        var qtyInput = $('input.qty');
        if (qtyInput.length === 0) return;

        var qty = parseInt(qtyInput.val());
        var container = $('#participant_fields_container');
        if (container.length === 0) return;

        var current = container.find('input').length;
        if (qty > current) {
            for (var i = current + 1; i <= qty; i++) {
                container.append('<input type="text" name="tour_participants[]" placeholder="Participant ' + i + '" style="width: 100%; margin-bottom: 5px;">');
            }
        } else if (qty < current) {
            container.find('input').slice(qty).remove();
        }
    }

    // Initialize on load
    updateParticipantFields();

    // Update on quantity change
    $('form.cart').on('change', 'input.qty', function() {
        updateParticipantFields();
    });
});
