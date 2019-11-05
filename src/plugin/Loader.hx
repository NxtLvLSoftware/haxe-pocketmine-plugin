package plugin;

import php.pthreads.Thread;
import pocketmine.plugin.PluginBase;

class Loader extends PluginBase {
    override public function onEnable():Void {
        var thread:Thread = new DummyThread();
        thread.start() && thread.join();

        getLogger().info("Plugin enabled!");
    }
}
