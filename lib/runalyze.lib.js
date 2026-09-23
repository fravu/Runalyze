/*
 * Lib for general stuff in Runalyze
 *
 * (c) 2014 Hannes Christiansen, http://www.runalyze.de/
 */
var Runalyze = (function($, parent){

	// Public

	var self = {};


	// Private

	var options = {
		dontReloadForConfigFlag:	'dont-reload-for-config',
		dontReloadForTrainingFlag:	'dont-reload-for-training'
	};

	var $body;

	var initHooks = [];
	var loadHooks = [];

	var isReady = false;


	// Private Methods

	function mergeOptions(newOptions) {
		options = $.extend({}, options, newOptions);
	}

	function initObjects() {
		$body = $("body");
	}

	function initResizer() {
		$(window).resize(function() {
			if (typeof RunalyzePlot != "undefined") {
				RunalyzePlot.resizeTrainingCharts();
				RunalyzePlot.setFullscreenSize();
			}
		});
	}

	function addOwnHooks() {
		self.addLoadHook('create-flot', self.createFlot);

		// TODO: move
		self.addLoadHook('resize-trainings', RunalyzePlot.resizeTrainingCharts);
	}

	function runInitHooks() {
		for (var key in initHooks) {
			initHooks[key]();
		}
	}

	function runLoadHooks() {
		for (var key in loadHooks) {
			loadHooks[key]();
		}
	}

	function reloadContentForConfig() {
		self.DataBrowser.reload();
		self.Statistics.reload();
		self.Panels.reloadAll( options.dontReloadForConfigFlag );
	}

	function reloadContentForTraining() {
		self.DataBrowser.reload();
		self.Statistics.reload();
		self.Panels.reloadAll( options.dontReloadForTrainingFlag );
	}


	// Dashboard reached via redirect from a standalone multi editor
	// (dashboard#multi-editor=1,2,3): open the multi editor in the overlay.
	function openMultiEditorFromHash() {
		var match = /^#multi-editor=([\d,]+)$/.exec(window.location.hash);

		if (!match || !self.hasContainer()) {
			return;
		}

		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		}

		self.Overlay.load('activity/multi-editor?ids=' + match[1]);
	}


	// Public Methods

	self.addInitHook = function(key, hook) {
		initHooks[key] = hook;
	};

	self.addLoadHook = function(key, hook) {
		loadHooks[key] = hook;
	};

	self.init = function(newOptions) {
		mergeOptions(newOptions);
		initObjects();

		if (!isReady) {
			initResizer();
		}

		addOwnHooks();

		runInitHooks();
		runLoadHooks();

		if (!isReady) {
			openMultiEditorFromHash();
		}

		isReady = true;
	};

	self.reinit = function() {
		runLoadHooks();
	};

	self.body = function() {
		return $body;
	};

	self.isReady = function() {
		return isReady;
	};

	self.hasContainer = function() {
		return ($("#container, #data-browser, #statistics-inner").length > 0);
	};

	self.createFlot = function() {
		$(document).trigger("createFlot");
	};

	self.flotChange = function(div, flot) {
		$(".flotChanger-"+div).addClass("unimportant");

		var newSelection = $(".flotChanger-id-"+flot);
		newSelection.removeClass("unimportant");
		newSelection.closest("li.with-submenu").find("> span.link").text(newSelection.text());

		$("#"+div+" .flot").addClass("flot-hide");
		$("#"+div+" #"+flot).removeClass("flot-hide");

		self.createFlot();
		RunalyzePlot.resize(flot);
	};

	self.toggleFieldset = function(b,c,d,e) {
		b.blur();
		var $c = $("#"+c);

		if (d === true) {
			$c.siblings().addClass("collapsed");
			$c.removeClass("collapsed");
		} else
			$c.toggleClass("collapsed");

		if (e.length > 0)
			self.Config.setActivityFormLegend(e, !$c.hasClass("collapsed"));

		return false;
	};

	self.goToNextMultiEditor = function() {
		var $current = $("#ajax-navigation tr.highlight");

		if ($current.next().length) {
			$current.next().click();
		} else {
			var $next = $current.siblings(':not(.edited):first');

			if ($next.length)
				$next.click();
			else
				$current.click();
		}
	};

	self.reloadPage = function() {
		location.reload();
	};

	self.reloadContent = function() {
		var container = $("#container");

		if (!container.length) {
			return;
		}

		// While #container has the loading class, CSS hides everything inside
		// it. Any failure below must therefore never leave that class in
		// place, or the whole page stays invisible and unclickable until a
		// full reload.
		var e = container.addClass( self.Options.loadingClass() );
		var url;

		try {
			var db = self.DataBrowser.currentTimes();

			url = 'index.php?';

			if (self.Statistics.showsTraining()) {
				url = url + 'id=' + self.Statistics.currentId();
			} else {
				url = url + 'pluginid=' + self.Statistics.currentId();
			}

			url = url + '&start=' + db.start + '&end=' + db.end + '#container > *';
		} catch (err) {
			(console.error || console.log).call(console, err.stack || err);
			self.reloadPage();
			return;
		}

		$.ajax({
			url: url
		}).done(function(data){
			var content = $('<div></div>').html(data).find('#container > *');
			container.html(content);

			self.init();
			e.hide().removeClass( self.Options.loadingClass() ).fadeIn();
		}).fail(function(xhr){
			e.removeClass( self.Options.loadingClass() );
			container.html('<p class="error">There was an error: '+ xhr.status +' '+ xhr.statusText +'</p>');
		});
	};

	self.reloadAllPlugins = function(id) {
		if (typeof id == "undefined" || id == "") {
			self.Statistics.reload();
			self.Panels.reloadAll();
		} else {
			self.reloadPlugin(id);
		}
	};

	self.reloadDataBrowserAndTraining = function() {
		self.DataBrowser.reload();
		self.Training.reload();
	};

	self.reloadPlugin = function(id) {
		if (self.Statistics.shows(id))
			self.Statistics.reload();
		else
			self.Panels.load(id);
	};

    self.try = function(callback, nodeOrCallback, msg) {
        try {
            callback();
        } catch (e) {
        	if (typeof nodeOrCallback == "function") {
        		nodeOrCallback();
			} else if (nodeOrCallback) {
                nodeOrCallback.html('<p class="text"><em>' + (msg || 'There was a problem.') + '</em></p>');
            }

            (console.error || console.log).call(console, e.stack || e);
        }
    };

	return self;
})(jQuery, undefined);


(function($, Runalyze){
	$.fn.extend({
		loadDiv: function(url, data, settings) {
			if (url == "#")
				return this;

			var e = this;

			return e.addClass( Runalyze.Options.loadingClass() ).load(url, data, function(response, status, xhr){
				if (status == "error") {
					e.html('<p class="error">There was an error: '+ xhr.status +' '+ xhr.statusText +'</p>');
				}

				if (e.attr('id') == "ajax") {
					Runalyze.reinit();
					Runalyze.Overlay.addCloseButton();
					e.removeClass( Runalyze.Options.loadingClass() );
				} else {
					Runalyze.reinit();
					e.hide().removeClass( Runalyze.Options.loadingClass() ).fadeIn();
				}

				if (settings) {
					if (settings.success) {
						settings.success();
					}
				}
			});
		}
	});
})(jQuery, Runalyze);
