<?php
function update_aop() {

	updateScheduler();
}

function updateScheduler(){
	require_once('modules/Schedulers/Scheduler.php');
	$scheduler = new Scheduler();
	$schedulers = $scheduler->get_full_list('','job = "function::pollMonitoredInboxesCustomAOP"');
	foreach($schedulers as $scheduler){
        $scheduler->job = "function::pollMonitoredInboxesAOP";
        $scheduler->save();
	}

}
