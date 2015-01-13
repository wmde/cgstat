#!/usr/bin/python
# -*- coding:utf-8 -*-
import time
import requests
import flask
from flask import Flask
from flup.server.fcgi import WSGIServer
import gp

app= Flask(__name__)

# generate information about all known graphs, used as template parameters
def gengraphinfo(hostmap):
    for graph in sorted(hostmap):   #, key=lambda g:hostmap[g]):
        yield( { "name": graph, "host": hostmap[graph] } )

def gengraphstats(hostmap):
    for graph in hostmap:
        yield """document.getElementById('arccount_%s').innerHTML='asdf';""" % graph

# glue function for 'streaming' a template which results in chunked transfer encoding
def stream_template(template_name, **context):
    app.update_template_context(context)
    t = app.jinja_env.get_template(template_name)
    rv = t.stream(context)
    #~ rv.enable_buffering(5)
    return rv

@app.route('/cgstat')
def cgstat():
    hostmap= requests.get("http://sylvester/hostmap/graphs.json").json()
    return flask.Response(stream_template('template.html', title="cgstat", graphs=gengraphinfo(hostmap), scripts=gengraphstats(hostmap)))


if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.debug= True
    WSGIServer(app).run()
