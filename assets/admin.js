(function ($) {
	'use strict';

	$('#wp-mcp-generate-token').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(wpMcpAdmin.ajaxUrl, {
			action: 'wp_mcp_generate_token',
			nonce: wpMcpAdmin.nonce
		})
			.done(function (response) {
				if (response.success && response.data.token) {
					$('#wp-mcp-token-value').text(response.data.token);
					$('#wp-mcp-token-result').show();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to generate token.');
				}
			})
			.fail(function () {
				alert('Request failed.');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	$('#wp-mcp-revoke-token').on('click', function () {
		if (!confirm('Revoke the API token? MCP clients will lose access.')) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(wpMcpAdmin.ajaxUrl, {
			action: 'wp_mcp_revoke_token',
			nonce: wpMcpAdmin.nonce
		})
			.done(function (response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to revoke token.');
				}
			})
			.fail(function () {
				alert('Request failed.');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});
})(jQuery);
