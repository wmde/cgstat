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
import time, datetime
import copy
import select
import socket
import resource
import requests
import asyncore
import flask
import json
from flask import Flask, request
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
    error_status= { "status": { "graph": "XXXX", "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR", "age": "ERROR" }, "errormsg": {} } }
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
            transports[transport.hin.fileno()]= transport
            poll.register(transport.hin.fileno(), select.POLLIN|select.POLLERR|select.POLLHUP|select.POLLNVAL|select.POLLPRI)
            for v in stat["status"]["content"]: stat["status"]["content"][v]= "..."
            yield stat
            transport.send("stats q\n")
            transport.send("list-meta\n")
        except Exception as ex:
            stat= copy.deepcopy(error_status)
            stat["status"]["graph"]= graph
            for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "%s" % str(ex)
            yield stat
            if not erricon_set: 
                yield { "favicon": "/cgstat/static/erricon.png" }
                erricon_set= True
    
    pollstart= time.time()
    while len(transports) and time.time()-pollstart<60:
        res= poll.poll(0.5)
        for row in res:
            stat= copy.deepcopy(error_status)
            stat["status"]["graph"]= transports[row[0]].graphname
            
            if row[1]==1:
                for cmd in range(2):  # we stuffed 2 commands in there, so we have to read 2 replies
                    try:
                        line= transports[row[0]].receive()  # receive the first status reply
                        if not line or not line.startswith("OK.") or not line.strip().endswith(':'):
                            # bad status
                            for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "INVALID RESPONSE '%s'" % str(line).strip()
                            if not erricon_set: 
                                yield { "favicon": "/cgstat/static/erricon.png" }
                                erricon_set= True
                        else:
                            # status ok, read data set
                            if cmd==0:  # first cmd -- 'stats q'
                                for l in transports[row[0]].make_source():
                                    if l[0]=='ArcCount':
                                        stat["status"]["content"]["arccount"]= l[1]
                                    elif l[0]=='ProcVirt':
                                        stat["status"]["content"]["virt"]= l[1]
                                    elif l[0]=='ProcRSS':
                                        stat["status"]["content"]["rss"]= l[1]
                            elif cmd==1:    # second cmd -- 'list-meta'
                                for l in transports[row[0]].make_source():
                                    if l[0]=='last_full_import':
                                        now= datetime.datetime.utcnow()
                                        lastimport= datetime.datetime.strptime(l[1], "%Y-%m-%dT%H:%M:%S")
                                        age= now-lastimport
                                        hours, remainder= divmod(age.seconds, 3600)
                                        minutes, seconds= divmod(remainder, 60)
                                        if age.days:
                                            stat["status"]["content"]["age"]= '<div class=errage>%02d:%02d:%02d</div>' % (age.days, hours, minutes)
                                            if not erricon_set: 
                                                yield { "favicon": "/cgstat/static/erricon.png" }
                                                erricon_set= True
                                        else:
                                            stat["status"]["content"]["age"]= '%02d:%02d:%02d' % (age.days, hours, minutes)
                    except socket.timeout:
                        for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "TIMEOUT"
                        if not erricon_set: 
                            yield { "favicon": "/cgstat/static/erricon.png" }
                            erricon_set= True
                    except Exception as ex:
                        for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= str(ex)
                        if not erricon_set: 
                            yield { "favicon": "/cgstat/static/erricon.png" }
                            erricon_set= True
            
            yield stat
            poll.unregister(row[0])
            transports[row[0]].close()
            del transports[row[0]]
    
    for t in transports:
        stat= error_status
        stat["status"]["graph"]= transports[t].graphname
        for v in stat["status"]["content"]: stat["status"]["errormsg"][v]= "TIMEOUT"
        yield stat
        if not erricon_set: 
            yield { "favicon": "/cgstat/static/erricon.png" }
            erricon_set= True
    
    # ... when finished:
    for t in transports:
        transports[t].close()


def gengraphstats2(hostmap):
    graphsbyhost= {}
    for graph in hostmap:
        if not hostmap[graph] in graphsbyhost:
            graphsbyhost[hostmap[graph]]= [graph]
        else:
            graphsbyhost[hostmap[graph]].append(graph)

    erricon_set= False
    for host in graphsbyhost:
        conn= client.Connection(client.ClientTransport(host))
        conn.connect()
        for graph in graphsbyhost[host]:
            stat= { "status": { "graph": graph, "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR", "age": "ERROR" }, "errormsg": {} } }
            conn.use_graph(str(graph))
            stats= conn.capture_stats("q")
            for row in stats:
                if row[0]=='ArcCount':
                    stat["status"]["content"]["arccount"]= row[1]
                elif row[0]=='ProcVirt':
                    stat["status"]["content"]["virt"]= row[1]
                elif row[0]=='ProcRSS':
                    stat["status"]["content"]["rss"]= row[1]
            meta= conn.capture_list_meta()
            for row in meta:
                if row[0]=='last_full_import':
                    now= datetime.datetime.utcnow()
                    lastimport= datetime.datetime.strptime(row[1], "%Y-%m-%dT%H:%M:%S")
                    age= now-lastimport
                    hours, remainder= divmod(age.seconds, 3600)
                    minutes, seconds= divmod(remainder, 60)
                    if age.days:
                        stat["status"]["content"]["age"]= '<div class=errage>%02d:%02d:%02d</div>' % (age.days, hours, minutes)
                        if not erricon_set: 
                            yield { "favicon": "/cgstat/static/erricon.png" }
                            erricon_set= True
                    else:
                        stat["status"]["content"]["age"]= '%02d:%02d:%02d' % (age.days, hours, minutes)
            yield stat
        conn.close()


from multiprocessing.dummy import Pool as ThreadPool
from multiprocessing import Queue
import threading
def gengraphstats3(hostmap):
    jobq= Queue()
    for graph in hostmap:
        jobq.put( (graph, hostmap[graph]) )
    
    resultq= Queue()
    
    def processjobs():
        erricon_set= False
        connections= { }
        while not jobq.empty():
            job= jobq.get()
            
            graph= job[0]
            host= job[1]
            
            if not host in connections:
                connections[host]= client.Connection(client.ClientTransport(host))
                connections[host].connect()
            
            connections[host].use_graph(str(graph))
            
            stat= { "status": { "graph": graph, "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR", "age": "ERROR" }, "errormsg": {} } }
            stats= connections[host].capture_stats("q")
            for row in stats:
                if row[0]=='ArcCount':
                    stat["status"]["content"]["arccount"]= row[1]
                elif row[0]=='ProcVirt':
                    stat["status"]["content"]["virt"]= row[1]
                elif row[0]=='ProcRSS':
                    stat["status"]["content"]["rss"]= row[1]
            meta= connections[host].capture_list_meta()
            for row in meta:
                if row[0]=='last_full_import':
                    now= datetime.datetime.utcnow()
                    lastimport= datetime.datetime.strptime(row[1], "%Y-%m-%dT%H:%M:%S")
                    age= now-lastimport
                    hours, remainder= divmod(age.seconds, 3600)
                    minutes, seconds= divmod(remainder, 60)
                    if age.days:
                        stat["status"]["content"]["age"]= '<div class=errage>%02d:%02d:%02d</div>' % (age.days, hours, minutes)
                        if not erricon_set: 
                            resultq.put( { "favicon": "/cgstat/static/erricon.png" } )
                            erricon_set= True
                    else:
                        stat["status"]["content"]["age"]= '%02d:%02d:%02d' % (age.days, hours, minutes)
            
            resultq.put(stat)
    
    threads= []
    for i in range(10):
        t= threading.Thread(target= processjobs)
        t.start()
        threads.append(t)
    
    def workers_alive():
        for t in threads:
            if t.is_alive(): return True
        return False
    
    while (not resultq.empty()) or workers_alive():
        yield resultq.get()

def mkgraphstats_stuffcmds(hostmap):
    graphsbyhost= {}
    for graph in hostmap:
        if not hostmap[graph] in graphsbyhost:
            graphsbyhost[hostmap[graph]]= [graph]
        else:
            graphsbyhost[hostmap[graph]].append(graph)
    
    graphstats= []

    erricon_set= False
    for host in graphsbyhost:
        sock= socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.connect((host, 6666))
        sock.settimeout(30)
        
        hin= sock.makefile("r")
        hout= sock.makefile("w")
        
        # returns tuple: (status (bool), statusline, dataset or None)
        def readreply():
            # get status line
            reply= hin.readline().strip()
            
            # error or failure occured
            if not (reply.startswith("OK") or reply.startswith("VALUE")):
                return (False, reply, None)
            
            # no error, no dataset
            if not reply.endswith(':'): 
                return (True, reply, None)
            
            # no error, reply with data set
            dataset= []
            while True:
                line= hin.readline().strip()
                if line=='': break
                dataset.append( line.split(',') )
            return (True, reply, dataset)
                

        for graph in graphsbyhost[host]:
            hout.write("use-graph %s\n" % graph)
            hout.write("stats q\n");
            hout.write("list-meta\n");        
        hout.flush()

        for graph in graphsbyhost[host]:
            ret= { "status": { "graph": graph, "content": { "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR", "age": "ERROR" }, "errormsg": {} } }
            # xxx todo... could shorten this:
            #status= { "graph": graph, "host": host, "arccount": "ERROR", "rss": "ERROR", "virt": "ERROR", "age": "ERROR", "errormsg": {} }
            
            # need to read all replies first
            reply_usegraph= readreply()
            reply_stats= readreply()
            reply_meta= readreply()
            
            # check status
            for reply in (reply_usegraph, reply_stats, reply_meta):
                if not reply[0]:
                    ret["status"]["errormsg"]= reply[1]
                    graphstats.append(ret)
                    continue

            # get stat values
            for retkey,key in ( ("arccount", "ArcCount"), ("rss", "ProcRSS"), ("virt", "ProcVirt") ):
                if not reply_stats[2]:
                    ret["status"]["errormsg"]= reply_stats[1]
                    graphstats.append(ret)
                    continue
                d= dict(reply_stats[2])
                if key in d:
                    ret["status"]["content"][retkey]= d[key]
                else:
                    ret["status"]["errormsg"]= reply_stats[1]
                    graphstats.append(ret)
                    continue
            
            # get age
            if (not reply_meta[0]) or (not reply_meta[2]) or (not "last_full_import" in dict(reply_meta[2])):
                ret["status"]["errormsg"]= reply_meta[1]
                graphstats.append(ret)
                continue
                
            lastimport_str= dict(reply_meta[2])["last_full_import"]
            now= datetime.datetime.utcnow()
            lastimport= datetime.datetime.strptime(lastimport_str, "%Y-%m-%dT%H:%M:%S")
            age= now-lastimport
            hours, remainder= divmod(age.seconds, 3600)
            minutes, seconds= divmod(remainder, 60)
            if age.days:
                ret["status"]["content"]["age"]= '<div class=errage>%02d:%02d:%02d</div>' % (age.days, hours, minutes)
                if not erricon_set: 
                    graphstats.append( { "favicon": "/cgstat/static/erricon.png" } ) # yield { "favicon": "/cgstat/static/erricon.png" }
                    erricon_set= True
            else:
                ret["status"]["content"]["age"]= '%02d:%02d:%02d' % (age.days, hours, minutes)
            
            graphstats.append(ret)
            
        hin.close()
        hout.close()
        sock.close()
    
    with open("/tmp/fuckfuckfuck", "w") as f:
        f.write(str(graphstats))
    return graphstats


# glue function for 'streaming' a template which results in chunked transfer encoding
def stream_template(template_name, **context):
    app.update_template_context(context)
    t = app.jinja_env.get_template(template_name)
    rv = t.stream(context)
    rv.enable_buffering(25)
    return rv

@app.route('/cgstat')
@app.route('/')
def cgstat():
    hostmapUri= "http://%s/hostmap/graphs.json" % ('localhost' if myhostname=='C086' else 'sylvester')
    hostmap= requests.get(hostmapUri).json()
    app.jinja_env.trim_blocks= True
    app.jinja_env.lstrip_blocks= True
    #~ response= flask.Response(stream_template('template.html', title="CatGraph Status", graphs=gengraphinfo(hostmap), updates=gengraphstats(hostmap), scripts=[]))
    response= flask.Response( flask.render_template('template.html', title="CatGraph Status", graphs=gengraphinfo(hostmap), updates=mkgraphstats_stuffcmds(hostmap), scripts=[]) )
    response.headers.add("Content-Encoding", "identity")
    return response


if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.config['DEBUG']= True
    #~ app.config['PROPAGATE_EXCEPTIONS']= None
    app.debug= True
    app.use_debugger= True
    WSGIServer(app).run()
    #~ app.run(debug=app.debug, use_debugger=app.debug)
