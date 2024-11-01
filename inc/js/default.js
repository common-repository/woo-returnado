	$j=jQuery.noConflict();
	
	function shadeIn(t){
		if(typeof t === 'undefined') t = 100;
		$j('#processing').fadeIn(t);
		$j('#rtnd_message').slideDown();
	}
	
	function shadeOut(t){
		sync.current = 999;
		if(typeof t === 'undefined') t = 100;
		$j('#rtnd_message').fadeOut(t,function(){
			$j('#processing').fadeOut(t-50,function(){
					$j('.info').hide();
					$j('#rtnd_message').css('height','auto');
					$j('#error_message').hide();
					$j('#success_message').hide();
					$j('#ok-btn').css('visibility','hidden');
				});
		});
	}
	
	function ShowInfo(){
		if ($j('.info').is(':visible')) {
			$j('.info').slideUp(200);
			$j('#rtnd_message').animate({"height": "220px"}, "medium");
			}
		else{
			$j('#rtnd_message').animate({"height": "380px"}, "medium");
			$j('.info').slideDown(500);
			}
	}

	var g_nonce = '';

    var sync = {};

    function resetSync(){
    	sync = {
            'objects': ['customers','categories','products','orders'],
            'current':	0,
            'page': 	1,
            'size':		20,
            'progress': 0,
			'res_type':	'success',
            'total':	0,
            'result':	0
        }
        $j('#processing .status').html('');
        $j('#processing .status-bar').width(0);
	}

    function getSyncTotal(nonce, full){
        $j.ajax({
            url:ajaxurl,
            data:{'action':'rtnd_ajax','do':'GetSyncTotal', 'full_sync':full, 'nonce':nonce},
            type:'post',
            success: function(data){
               	sync.total = Number(data);
            },
            error: function(a,b,error){
                $j('#processing .status').html(error);
            }
        });
    }


	function ReturnadoSync(nonce, full){
		g_nonce = nonce;
		getSyncTotal(nonce, full);
        shadeIn(300);
        resetSync();
		GoSync(full);
	}

    function GoSync(full){
        console.log('full sync is:' + full);
        $j.ajax({
            url:ajaxurl,
            data:{ 	'action':'rtnd_ajax',
					'do':'ReturnadoSync',
                    'full_sync' : full,
					'sync_object':sync.objects[sync.current],
					'page':sync.page,
					'size':sync.size,
					'nonce':g_nonce
				},
            type:'post',
            success: function(datax){
                var dataz = $j.parseJSON(datax);
                var rez_msg = String(dataz.rez);
                var rez_num = Number(dataz.rez);
                var mem = String(dataz.mem);
            	if(rez_msg.indexOf('ERROR')>0){
                    sync.current++;
                    sync.page=1;
                    sync.res_type = 'error';
                    $j('#processing .info').append(rez_msg+'<br/>');
				}else{
                    sync.result = rez_num;
                    sync.progress += sync.result;
                    if(sync.result<sync.size) {
                        if(sync.result) syncStatus(mem);
                        sync.current++;
                        sync.page=1;

                    }else {
                        syncStatus(mem);
                        sync.page++;
                    }
				}

                if(sync.current<sync.objects.length) GoSync(full);
                else{
                    $j('#rtnd_message').slideUp(100);
                    $j('#'+sync.res_type+'_message').slideDown(100);
                    $j('#ok-btn').css('visibility','visible');
                    $j('#close-btn').prop('disabled',false);
                }

            },
            error: function(a,b,error){
                $j('#rtnd_message').slideUp(100);
                $j('#error_message').slideDown(100);
                $j('#ok-btn').css('visibility','visible');
                $j('#close-btn').prop('disabled',false);
                $j('#processing .info').html(error);
            }
        });
	}

	function syncStatus(mem){
        $j('#processing .mem-status').html(mem);
		var wid = sync.progress / sync.total * $j('#processing .status-bar').parent().width();
        $j('#processing .status').html(sync.page + ' x ' + sync.result + ' ' + sync.objects[sync.current] + '...');
        $j('#processing .status-bar').width(wid);
	}

	$j(document).ready(function($){
	   $('a.log-link').on('click', function(){
	      $('div.change-log').slideToggle();
       });
    });