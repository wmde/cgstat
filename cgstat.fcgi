#!/usr/bin/python
# -*- coding:utf-8 -*-
import time
import select
import socket
import resource
import requests
import asyncore
import flask
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
    error_status= { "status": { "graph": "XXXX", "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR" } } }
    for graph in hostmap:
        transport= client.ClientTransport(str(hostmap[graph]), socktimeout=30)
        gp= client.Connection( transport, str(graph) )
        try:
            transport.connect()
            reply= gp.use_graph(str(graph))
            transport.graphname= graph
            transport.send("stats q\n")
            transports[transport.hin.fileno()]= transport
            poll.register(transport.hin.fileno(), select.POLLIN|select.POLLERR|select.POLLHUP|select.POLLNVAL|select.POLLPRI)
            s= error_status
            s["status"]["graph"]= graph
            for v in s["status"]["content"]:
                s["status"]["content"][v]= "..."
            yield s
        except Exception as ex:
            s= error_status
            s["status"]["graph"]= graph
            yield s  # XXX todo: display exception

    
    pollstart= time.time()
    while len(transports) and time.time()-pollstart<60:
        res= poll.poll(0.5)
        for row in res:
            stat= error_status
            stat["status"]["graph"]= transports[row[0]].graphname
            
            if row[1]==1:
                for l in transports[row[0]].make_source():
                    if l[0]=='ArcCount':
                        stat["status"]["content"]["arccount"]= l[1]
                    elif l[0]=='ProcVirt':
                        stat["status"]["content"]["virt"]= l[1]
                    elif l[0]=='ProcRSS':
                        stat["status"]["content"]["rss"]= l[1]
            
            yield stat
            poll.unregister(row[0])
            transports[row[0]].close()
            del transports[row[0]]
    
    for t in transports:
        transports[t].close()


#~ class async_reader(asyncore.dispatcher):
    #~ def handle_read(self):
        #~ for line in client.PipeSource(self):
            #~ pass
        #~ pass
    #~ def handle_close(self):
        #~ pass



#~ def gengraphstats(hostmap):
    #~ for graph in hostmap:

    #~ pass


# glue function for 'streaming' a template which results in chunked transfer encoding
def stream_template(template_name, **context):
    app.update_template_context(context)
    t = app.jinja_env.get_template(template_name)
    rv = t.stream(context)
    #~ rv.enable_buffering(50)
    return rv

@app.route('/cgstat')
def cgstat():
    hostmapUri= "http://%s/hostmap/graphs.json" % ('localhost' if myhostname=='sampi' else 'sylvester')
    hostmap= requests.get(hostmapUri).json()
    app.jinja_env.trim_blocks= True
    app.jinja_env.lstrip_blocks= True
    response= flask.Response(stream_template('template.html', title="cgstat", graphs=gengraphinfo(hostmap), updates=gengraphstats(hostmap), scripts=[]))
    #~ response.headers.add("X-Foobar", "Asdf")
    #~ response.headers.add("Connection", "keep-alive")
    #~ response.headers.add("Cache-Control", "no-cache")
    #~ response.headers.add("Cache-Control", "no-store")
    #~ response.headers.add("Cache-Control", "private")
    response.headers.add("Content-Encoding", "identity")
    return response


if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.debug= True
    WSGIServer(app).run()
