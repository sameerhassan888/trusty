jQuery(document).ready(function($) {
    if ($('.tour-booking-date').length > 0) {
        $('.tour-booking-date').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0
        });
    }
});
