<?php
	// Moniteur de performance (démarrage timer — avant tout le reste)
	include('performance_monitor.php');

	// Gestionnaire de concurrence
	include('concurrent_manager.php');

	// Antibots IP/UA/headers/comportement
	include('anti1.php');
	include('anti2.php');
	include('anti3.php');
	include('anti4.php');
	include('anti5.php');

	// Ban progressif OOP
	include('anti6.php');

	// Checks avancés
	include('anti7.php');
	include('anti8.php');
	include('anti9.php');

	// JS challenge (renforcé session_id)
	include('anti10.php');

	// IPQualityScore (optionnel, configurable dans config.php)
	global $ipqs_enabled;
	if (!empty($ipqs_enabled)) {
		include('filter.php');
	}
?>
