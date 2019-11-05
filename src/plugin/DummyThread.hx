package plugin;

import pocketmine.Thread;

class DummyThread extends Thread {

    public function new():Void {
        super();
    }

    override function run():Void {
        registerClassLoader();
        php.Lib.println("Hello from a thread!");
    }
}
