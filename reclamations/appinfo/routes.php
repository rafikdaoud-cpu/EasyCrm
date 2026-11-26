<?php
return ['routes' => [
	['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	['name' => 'api#me', 'url' => '/api/me', 'verb' => 'GET'],
	['name' => 'api#claims', 'url' => '/api/claims', 'verb' => 'GET'],
	['name' => 'api#create', 'url' => '/api/claims', 'verb' => 'POST'],
	['name' => 'api#show', 'url' => '/api/claims/{id}', 'verb' => 'GET'],
	['name' => 'api#update', 'url' => '/api/claims/{id}/update', 'verb' => 'POST'],
	['name' => 'api#action', 'url' => '/api/claims/{id}/action', 'verb' => 'POST'],
	['name' => 'api#destroy', 'url' => '/api/claims/{id}/delete', 'verb' => 'POST'],
	['name' => 'api#import', 'url' => '/api/import', 'verb' => 'POST'],
	['name' => 'api#saveServices', 'url' => '/api/services', 'verb' => 'POST'],
	['name' => 'api#stats', 'url' => '/api/stats', 'verb' => 'GET'],
	['name' => 'api#export', 'url' => '/api/export', 'verb' => 'GET'],
]];
