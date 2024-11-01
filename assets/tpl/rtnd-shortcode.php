<?php
/**
 * Created by PhpStorm.
 * User: stim
 * Date: 07.02.18
 * Time: 11:59
 */
/**
 * Template for Returnado shortcode
 */

?>

<script>
    var queryString = function() {
        var query_string = {};
        var query = window.location.search.substring(1);
        var vars = query.split("&");
        for (var i = 0; i < vars.length; i++) {
            var pair = vars[i].split("=");
            if (typeof query_string[pair[0]] === "undefined") {
                query_string[pair[0]] = decodeURIComponent(pair[1]);
            } else if (typeof query_string[pair[0]] === "string") {
                var arr = [query_string[pair[0]], decodeURIComponent(pair[1])];
                query_string[pair[0]] = arr;
            } else {
                query_string[pair[0]].push(decodeURIComponent(pair[1]));
            }
        }
        return query_string;
    }();
    var returnadoToken = queryString.returnadoToken;
    widgetHost = "<?php echo $remote_host ?>/widget-ui";
    widgetUrl = "/index.html#/orders";
    var returnadoOptions = {
        widget_host: widgetHost,
        widget_url: widgetUrl,
        shop_id: <?php echo $shop_id ?>,
        returnado_token: returnadoToken,
        lang: "<?php echo substr(get_locale(),0,2)?>"
    };
    (function() {
        var script = document.createElement("script");
        script.type = "text/javascript";
        script.async = true;
        script.src = returnadoOptions.widget_host + "/returnado.js";
        document.getElementsByTagName("head")[0].appendChild(script);
    })();
</script>

<style>
    div#returnadoLoginWrapperId {
        overflow: hidden;
        height: auto;
        min-height: 587px;
        width: 100%;
        max-width: 1020px;
        margin: auto;
    }

    div#returnadoLoginWrapperId .loader,
    div#returnadoLoginWrapperId .loader:after {
        border-radius: 50%;
        width: 10em;
        height: 10em;
    }

    div#returnadoLoginWrapperId .loader {
        margin: 60px auto;
        font-size: 10px;
        top: 150px;
        position: relative;
        text-indent: -9999em;
        border-top: 1.1em solid rgba(192, 192, 192, 0.2);
        border-right: 1.1em solid rgba(192, 192, 192, 0.2);
        border-bottom: 1.1em solid rgba(192, 192, 192, 0.2);
        border-left: 1.1em solid #c0c0c0;
        -webkit-transform: translateZ(0);
        -ms-transform: translateZ(0);
        transform: translateZ(0);
        -webkit-animation: load8 1.1s infinite linear;
        animation: load8 1.1s infinite linear;
    }

    @-webkit-keyframes load8 {
        0% {
            -webkit-transform: rotate(0deg);
            transform: rotate(0deg);
        }
        100% {
            -webkit-transform: rotate(360deg);
            transform: rotate(360deg);
        }
    }

    @keyframes load8 {
        0% {
            -webkit-transform: rotate(0deg);
            transform: rotate(0deg);
        }
        100% {
            -webkit-transform: rotate(360deg);
            transform: rotate(360deg);
        }
    }

    div#returnadoLoginWrapperId *:nth-child(1) {
        display: block !important;
    }

    div#returnadoLoginWrapperId *:nth-child(2) {
        display: none !important;
    }
</style>

<div id="returnadoLoginWrapperId" class="flattered">
    <div id="returnadoLoginLoaderId" class="loader">Loading...</div>
</div>
