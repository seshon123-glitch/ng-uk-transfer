(function($){
    'use strict';

    var dashboardCharts = [];

    function normalize(value) {
        return String(value || '').toLowerCase();
    }

    function closeSuggestions(wrapper) {
        wrapper.find('.nguk-customer-suggestions').removeClass('is-open').empty();
    }

    function initCustomerAutocomplete() {
        $('.nguk-customer-autocomplete').each(function(){
            var wrapper = $(this);
            var select = $(wrapper.data('select'));
            var input = wrapper.find('input[type="search"]');
            var suggestions = wrapper.find('.nguk-customer-suggestions');
            var direction = wrapper.data('direction') || '';
            var ajaxCache = {};
            var searchTimer = null;

            if (!select.length || !input.length || wrapper.data('autocomplete-ready')) {
                return;
            }

            wrapper.data('autocomplete-ready', true);

            function options() {
                return select.find('option').filter(function(){
                    return $(this).val() !== '';
                }).map(function(){
                    var option = $(this);
                    var name = $.trim(option.text());
                    var phone = option.data('phone') || '';
                    return {
                        id: option.val(),
                        name: name,
                        phone: phone,
                        haystack: normalize(name + ' ' + phone)
                    };
                }).get();
            }

            function choose(item) {
                if (!select.find('option[value="' + item.id + '"]').length) {
                    select.append(
                        $('<option></option>')
                            .val(item.id)
                            .attr('data-phone', item.phone || '')
                            .text(item.name)
                    );
                }

                select.val(item.id).trigger('change');
                input.val(item.name + (item.phone ? ' - ' + item.phone : ''));
                closeSuggestions(wrapper);
            }

            function renderMatches(matches) {
                suggestions.empty();

                if (!matches.length) {
                    closeSuggestions(wrapper);
                    return;
                }

                matches.forEach(function(item){
                    var button = $('<button type="button" class="nguk-customer-suggestion"></button>');
                    button.append($('<strong></strong>').text(item.name));
                    button.append($('<span></span>').text(item.phone || 'No phone number saved'));
                    button.on('click', function(){
                        choose(item);
                    });
                    suggestions.append(button);
                });

                suggestions.addClass('is-open');
            }

            function searchAjax(term) {
                if (!window.ngukEnhancements || !window.ngukEnhancements.ajaxUrl || !direction) {
                    return false;
                }

                if (ajaxCache[term]) {
                    renderMatches(ajaxCache[term]);
                    return true;
                }

                $.get(window.ngukEnhancements.ajaxUrl, {
                    action: 'nguk_customer_search',
                    nonce: window.ngukEnhancements.customerSearchNonce,
                    direction: direction,
                    term: term
                }).done(function(response){
                    var matches = response && response.success && response.data ? response.data : [];
                    ajaxCache[term] = matches.map(function(item){
                        return {
                            id: String(item.id),
                            name: item.name || '',
                            phone: item.phone || '',
                            haystack: normalize((item.name || '') + ' ' + (item.phone || ''))
                        };
                    });
                    renderMatches(ajaxCache[term]);
                }).fail(function(){
                    closeSuggestions(wrapper);
                });

                return true;
            }

            input.on('input', function(){
                var term = normalize(input.val());

                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                }

                if (term.length < 2) {
                    closeSuggestions(wrapper);
                    return;
                }

                searchTimer = window.setTimeout(function(){
                    if (searchAjax(term)) {
                        return;
                    }

                    var matches = options().filter(function(item){
                        return item.haystack.indexOf(term) !== -1;
                    }).slice(0, 12);

                    renderMatches(matches);
                }, 180);
            });

            select.on('change', function(){
                var selected = select.find(':selected');
                if (selected.val()) {
                    input.val($.trim(selected.text()) + (selected.data('phone') ? ' - ' + selected.data('phone') : ''));
                }
            });

            $(document).on('click', function(event){
                if (!wrapper[0].contains(event.target)) {
                    closeSuggestions(wrapper);
                }
            });

            select.trigger('change');
        });
    }

    function initUkngBidirectionalCalculator() {
        var poundsInput = $('#ukng_pounds_sent');
        var nairaInput = $('#ukng_naira_preview');
        var rateInput = $('#ukng_rate');
        var updating = false;

        if (!poundsInput.length || !nairaInput.length || !rateInput.length) {
            return;
        }

        function rate() {
            return parseFloat(rateInput.val()) || 0;
        }

        function fromPounds() {
            if (updating) {
                return;
            }

            updating = true;
            var pounds = parseFloat(poundsInput.val()) || 0;
            var currentRate = rate();
            nairaInput.val(pounds > 0 && currentRate > 0 ? (pounds * currentRate).toFixed(2) : '');
            updating = false;
        }

        function fromNaira() {
            if (updating) {
                return;
            }

            updating = true;
            var naira = parseFloat(nairaInput.val()) || 0;
            var currentRate = rate();
            poundsInput.val(naira > 0 && currentRate > 0 ? (naira / currentRate).toFixed(2) : '');
            updating = false;
        }

        poundsInput.on('input keyup change', fromPounds);
        nairaInput.on('input keyup change', fromNaira);
        rateInput.on('change', function(){
            if (poundsInput.val()) {
                fromPounds();
            } else if (nairaInput.val()) {
                fromNaira();
            }
        });
    }

    function chartColors() {
        return {
            turnover: '#0f766e',
            profit: '#f59e0b',
            volume: '#2563eb',
            grid: 'rgba(148, 163, 184, 0.26)'
        };
    }

    function readChartData(element) {
        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            return {};
        }
    }

    function makeChart(canvas, config) {
        if (!window.Chart || !canvas || $(canvas).data('chart-ready')) {
            return;
        }

        $(canvas).data('chart-ready', true);
        dashboardCharts.push(new window.Chart(canvas, config));
    }

    function resizeDashboardCharts() {
        dashboardCharts.forEach(function(chart){
            if (chart && typeof chart.resize === 'function') {
                chart.resize();
            }
        });
    }

    function initDashboardCharts() {
        var dataNode = document.getElementById('ngukDashboardChartData');
        if (!dataNode || !window.Chart) {
            return;
        }

        var data = readChartData(dataNode);
        var labels = data.labels || [];
        var colors = chartColors();
        var commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: colors.grid } }
            }
        };

        makeChart(document.getElementById('ngukTurnoverChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: data.turnoverLabel || 'Monthly Turnover',
                    data: data.turnover || [],
                    backgroundColor: colors.turnover,
                    borderRadius: 6
                }]
            },
            options: commonOptions
        });

        makeChart(document.getElementById('ngukProfitChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: data.profitLabel || 'Monthly Profit',
                    data: data.profit || [],
                    borderColor: colors.profit,
                    backgroundColor: 'rgba(245, 158, 11, 0.16)',
                    fill: true,
                    tension: 0.32
                }]
            },
            options: commonOptions
        });

        makeChart(document.getElementById('ngukVolumeChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Transaction Volume',
                    data: data.volume || [],
                    borderColor: colors.volume,
                    backgroundColor: 'rgba(37, 99, 235, 0.14)',
                    fill: true,
                    tension: 0.32
                }]
            },
            options: commonOptions
        });

        window.setTimeout(resizeDashboardCharts, 50);
    }

    function initDashboardChartsWhenReady(attempt) {
        attempt = attempt || 0;

        if (window.Chart) {
            initDashboardCharts();
            return;
        }

        if (document.getElementById('ngukDashboardChartData') && attempt < 20) {
            window.setTimeout(function(){
                initDashboardChartsWhenReady(attempt + 1);
            }, 150);
        }
    }

    $(function(){
        initCustomerAutocomplete();
        initUkngBidirectionalCalculator();
        initDashboardChartsWhenReady(0);

        $(document).on('click', '.nguk-nav-button[data-nguk-tab="overview"], .ukng-nav a[href*="ukng_view=overview"]', function(){
            window.setTimeout(function(){
                initDashboardChartsWhenReady(0);
                resizeDashboardCharts();
            }, 120);
        });
    });
})(jQuery);
