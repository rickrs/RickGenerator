(function($){
  $(function(){
    var $provider = $('#aiw-provider');
    var $status = $('#aiw-fetch-status');
    $(document).on('click','#aiw-fetch-models', function(){
      $status.text('Buscando modelos...');
      $.post(AIW_MODELS.ajax_url, {
        action: 'aiw_fetch_models',
        nonce: AIW_MODELS.nonce,
        provider: $provider.val()
      }).done(function(resp){
        if (!resp || !resp.success){
          $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'Falha ao consultar.');
          return;
        }
        var list = resp.data.models || [];
        var $sel = $('#aiw-model');
        $sel.empty();
        if (list.length === 0){
          $sel.append($('<option/>').val('').text('Nenhum modelo retornado'));
          $status.text('Nenhum modelo retornado pela API.');
          return;
        }
        list.forEach(function(id){ $sel.append($('<option/>').val(id).text(id)); });
        $status.text('Modelos atualizados.');
      }).fail(function(xhr){
        var msg = 'Erro ao buscar modelos.';
        try{ var data = JSON.parse(xhr.responseText); if (data && data.data && data.data.message) msg = data.data.message; }catch(e){}
        $status.text(msg);
      });
    });

    function setModeUI(){
      if ($('input[name="aiw_settings[model_mode]"]:checked').val()==='custom'){
        $('#aiw-model').prop('disabled', true);
        $('#aiw-model-custom').show();
      } else {
        $('#aiw-model').prop('disabled', false);
        $('#aiw-model-custom').hide();
      }
    }
    $(document).on('change','input[name="aiw_settings[model_mode]"]', setModeUI);
    setModeUI();
  });
})(jQuery);