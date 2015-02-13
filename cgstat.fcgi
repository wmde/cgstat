#!/usr/bin/python
# -*- coding:utf-8 -*-
# cgstat: Web-based status app for CatGraph
# Copyright (C) Wikimedia Deutschland e.V.
# Authors: Johannes Kroll
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

import sys
import time
import copy
import select
import socket
import resource
import requests
import asyncore
import flask
import json
from flask import Flask
from flup.server.fcgi import WSGIServer
from gp import *

app= Flask(__name__)
myhostname= socket.gethostname()

# generate information about all known graphs, used as template parameters
def gengraphinfo(hostmap):
    for graph in sorted(hostmap):   #, key=lambda g:hostmap[g]):
        yield( { "name": graph, "host": hostmap[graph] } )

def gengraphstats_old(hostmap):
    transports= {}      # socket fileno => ClientTransport
    read_sockets= []    # filenos for select()
    nofile= resource.getrlimit(resource.RLIMIT_NOFILE)
    resource.setrlimit(resource.RLIMIT_NOFILE, (nofile[1],nofile[1]))
    #~ i= 0
    for graph in hostmap:
        transport= client.ClientTransport(str(hostmap[graph]), socktimeout=30)
        gp= client.Connection( transport, str(graph) )
        #~ time.sleep(0.01)
        try:
            transport.connect()
            gp.use_graph(str(graph))
            transport.graphname= graph
            transport.send("help\n")
            transports[transport.socket.fileno()]= transport
            read_sockets.append(transport.socket.fileno())
        except Exception as ex:
            yield """document.getElementById('arccount_%s').innerHTML="%s";""" % (graph, str(ex))
        #~ i+= 1
        #~ if i>(302-25):
            #~ break
    
    #~ yield "read_sockets: " + str(read_sockets)
    
    starttime= time.time()
    while (len(read_sockets)) and (time.time()-starttime<30):
        (read_ready,wr_ready,exc)= select.select(read_sockets, [], [], 5)
        for socket in read_ready:
            content= ""
            for line in transports[socket].make_source():
                #~ yield line
                content+= str(line) + '<br>'
                yield "document.getElementById('arccount_%s').innerHTML+= '%s';" % (transports[socket].graphname, line[0].strip()+'<br>')
            yield """document.getElementById('arccount_%s').innerHTML="[OK]";""" % (transports[socket].graphname)
            read_sockets.remove(socket)
            transports[socket].close()
            del transports[socket]
    for socket in read_sockets:
        #~ read_sockets.remove(socket)
        transports[socket].close()
        del transports[socket]
    
    #~ yield """document.getElementById('arccount_%s').innerHTML='asdf';""" % graph


def gengraphstats(hostmap):
    poll= select.poll()
    transports= {}      # socket fileno => ClientTransport
    nofile= resource.getrlimit(resource.RLIMIT_NOFILE)
    resource.setrlimit(resource.RLIMIT_NOFILE, (nofile[1],nofile[1]))
    error_status= { "status": { "graph": "XXXX", "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR" }, "errormsg": {} } }
    erricon_set= False
    for graph in hostmap:
        transport= client.ClientTransport(str(hostmap[graph]), socktimeout=5)
        gp= client.Connection( transport, str(graph) )
        try:
            stat= copy.deepcopy(error_status)
            stat["status"]["graph"]= graph
            transport.connect()
            reply= gp.execute('use-graph %s' % str(graph))
            transport.graphname= graph
            transport.send("stats q\n")
            transports[transport.hin.fileno()]= transport
            poll.register(transport.hin.fileno(), select.POLLIN|select.POLLERR|select.POLLHUP|select.POLLNVAL|select.POLLPRI)
            for v in stat["status"]["content"]: stat["status"]["content"][v]= "..."
            yield stat
        except Exception as ex:
            stat= copy.deepcopy(error_status)
            stat["status"]["graph"]= graph
            for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "%s" % str(ex)
            yield stat
            if not erricon_set: yield { "favicon": "/cgstat/static/erricon.png" }
    
    pollstart= time.time()
    while len(transports) and time.time()-pollstart<60:
        res= poll.poll(0.5)
        for row in res:
            stat= copy.deepcopy(error_status)
            stat["status"]["graph"]= transports[row[0]].graphname
            
            if row[1]==1:
                try:
                    line= transports[row[0]].receive()
                    if not line or not line.startswith("OK.") or not line.strip().endswith(':'):
                        for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "INVALID RESPONSE '%s'" % str(line).strip()
                        if not erricon_set: yield { "favicon": "/cgstat/static/erricon.png" }
                    else:
                        for l in transports[row[0]].make_source():
                            if l[0]=='ArcCount':
                                stat["status"]["content"]["arccount"]= l[1]
                            elif l[0]=='ProcVirt':
                                stat["status"]["content"]["virt"]= l[1]
                            elif l[0]=='ProcRSS':
                                stat["status"]["content"]["rss"]= l[1]
                except socket.timeout:
                    for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "TIMEOUT"
                    if not erricon_set: yield { "favicon": "/cgstat/static/erricon.png" }
            
            yield stat
            poll.unregister(row[0])
            transports[row[0]].close()
            del transports[row[0]]
    
    for t in transports:
        stat= error_status
        stat["status"]["graph"]= transports[t].graphname
        for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "TIMEOUT"
        yield stat
        if not erricon_set: yield { "favicon": "/cgstat/static/erricon.png" }
    
    # ... when finished:
    for t in transports:
        transports[t].close()

# glue function for 'streaming' a template which results in chunked transfer encoding
def stream_template(template_name, **context):
    app.update_template_context(context)
    t = app.jinja_env.get_template(template_name)
    rv = t.stream(context)
    #~ rv.enable_buffering(50)
    return rv

@app.route('/cgstat')
@app.route('/')
#~ @app.route('/catgraph/cgstat')
#~ @app.route('/catgraph/cgstat/')
def cgstat():
    hostmapUri= "http://%s/hostmap/graphs.json" % ('localhost' if myhostname=='sampi' else 'sylvester')
    hostmap= requests.get(hostmapUri).json()
    app.jinja_env.trim_blocks= True
    app.jinja_env.lstrip_blocks= True
    response= flask.Response(stream_template('template.html', title="CatGraph Status", graphs=gengraphinfo(hostmap), updates=gengraphstats(hostmap), scripts=[]))
    #~ response.headers.add("X-Foobar", "Asdf")
    #~ response.headers.add("Connection", "keep-alive")
    #~ response.headers.add("Cache-Control", "no-cache")
    #~ response.headers.add("Cache-Control", "no-store")
    #~ response.headers.add("Cache-Control", "private")
    response.headers.add("Content-Encoding", "identity")
    return response


if __name__ == '__main__':
    #~ import cgitb
    #~ cgitb.enable()
    #~ app.config['DEBUG']= True
    app.debug= True
    app.use_debugger= True
    sys.stderr.write("__MAIN__\n")
    WSGIServer(app).run()
    #~ app.run(debug=app.debug, use_debugger=app.debug)
