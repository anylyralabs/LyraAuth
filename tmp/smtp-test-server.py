#!/usr/bin/env python3
"""最小 SMTP 测试服务器。

支持三种模式，用于验证 LyraAuth 的 MailService：
  --mode plain     明文（端口 2525）
  --mode starttls  明文 + STARTTLS 升级（端口 2587）
  --mode ssl       隐式 TLS（端口 2465）

同时支持 AUTH LOGIN，账号密码取自 --user / --pass。
收到的邮件写入 --out（JSON Lines）。

用法：
  python3 smtp-test-server.py --mode starttls --cert /tmp/x.crt --key /tmp/x.key --out /tmp/mail.jsonl
"""

import argparse
import base64
import json
import socket
import ssl
import threading
import time

RECEIVED = []
LOCK = threading.Lock()


def log(msg):
    print(f"[smtp] {msg}", flush=True)


def read_line(conn):
    data = b""
    while not data.endswith(b"\r\n"):
        chunk = conn.recv(1)
        if not chunk:
            return None
        data += chunk
    return data.decode("utf-8", "replace").rstrip("\r\n")


def send(conn, text):
    conn.sendall((text + "\r\n").encode())


def handle(conn, addr, args, tls_context):
    state = {"tls": args.mode == "ssl", "user": None, "authed": False}
    try:
        send(conn, "220 test.local ESMTP LyraAuth-Test")
        seen_starttls = False
        mail_from = None
        rcpt_to = []

        while True:
            line = read_line(conn)
            if line is None:
                return
            upper = line.upper()

            if upper.startswith("EHLO"):
                features = ["250-test.local", "250-8BITMIME", "250-SIZE 10485760"]
                if args.mode == "starttls" and not state["tls"] and not seen_starttls:
                    features.append("250-STARTTLS")
                features.append("250-AUTH LOGIN PLAIN")
                features.append(f"250 OK{tls_word(state)}")
                for f in features:
                    send(conn, f)
                continue

            if upper.startswith("HELO"):
                send(conn, "250 test.local")
                continue

            if upper == "STARTTLS":
                if args.mode != "starttls" or state["tls"]:
                    send(conn, "454 TLS not available")
                    continue
                send(conn, "220 Ready to start TLS")
                try:
                    conn = tls_context.wrap_socket(conn, server_side=True)
                    state["tls"] = True
                    seen_starttls = True
                    log("STARTTLS 升级成功")
                except Exception as exc:  # noqa: BLE001
                    log(f"STARTTLS 失败: {exc}")
                    return
                continue

            if upper.startswith("AUTH LOGIN"):
                send(conn, "334 VXNlcm5hbWU6")
                user_b64 = read_line(conn)
                send(conn, "334 UGFzc3dvcmQ6")
                pass_b64 = read_line(conn)
                user = base64.b64decode(user_b64 or "").decode()
                password = base64.b64decode(pass_b64 or "").decode()
                if user == args.user and password == args.password:
                    state["authed"] = True
                    state["user"] = user
                    send(conn, "235 2.7.0 Authentication successful")
                else:
                    send(conn, "535 5.7.8 Authentication credentials invalid")
                continue

            if upper.startswith("MAIL FROM:"):
                if args.require_auth and not state["authed"]:
                    send(conn, "530 5.7.0 Authentication required")
                    continue
                mail_from = line[10:].strip()
                rcpt_to = []
                send(conn, "250 2.1.0 Ok")
                continue

            if upper.startswith("RCPT TO:"):
                rcpt_to.append(line[8:].strip())
                send(conn, "250 2.1.5 Ok")
                continue

            if upper == "DATA":
                send(conn, "354 End data with <CR><LF>.<CR><LF>")
                body = []
                while True:
                    l = read_line(conn)
                    if l is None:
                        return
                    if l == ".":
                        break
                    body.append(l)
                record = {
                    "mode": args.mode,
                    "tls": state["tls"],
                    "auth": state["authed"],
                    "mail_from": mail_from,
                    "rcpt_to": rcpt_to,
                    "data": "\n".join(body),
                    "at": time.strftime("%Y-%m-%d %H:%M:%S"),
                }
                with LOCK:
                    RECEIVED.append(record)
                    with open(args.out, "a", encoding="utf-8") as fh:
                        fh.write(json.dumps(record, ensure_ascii=False) + "\n")
                log(f"收到邮件 rcpt={rcpt_to} tls={state['tls']} bytes={len(record['data'])}")
                send(conn, "250 2.0.0 Ok: queued")
                continue

            if upper == "RSET":
                mail_from, rcpt_to = None, []
                send(conn, "250 Ok")
                continue

            if upper == "NOOP":
                send(conn, "250 Ok")
                continue

            if upper == "QUIT":
                send(conn, "221 Bye")
                return

            send(conn, "502 5.5.2 Command not implemented")
    except Exception as exc:  # noqa: BLE001
        log(f"连接异常: {exc}")
    finally:
        try:
            conn.close()
        except Exception:  # noqa: BLE001
            pass


def tls_word(state):
    return "(TLS)" if state["tls"] else "(plain)"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=["plain", "starttls", "ssl"], default="plain")
    parser.add_argument("--port", type=int, default=0)
    parser.add_argument("--user", default="mailer@test.local")
    parser.add_argument("--password", default="test-pass-2026")
    parser.add_argument("--cert", default="")
    parser.add_argument("--key", default="")
    parser.add_argument("--out", default="/tmp/opencode/smtp-mail.jsonl")
    parser.add_argument("--require-auth", action="store_true")
    args = parser.parse_args()

    default_ports = {"plain": 2525, "starttls": 2587, "ssl": 2465}
    port = args.port or default_ports[args.mode]

    tls_context = None
    if args.mode in ("starttls", "ssl"):
        if not args.cert or not args.key:
            raise SystemExit("STARTTLS/SSL 模式需要 --cert 与 --key")
        tls_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls_context.load_cert_chain(args.cert, args.key)

    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(("127.0.0.1", port))
    sock.listen(16)
    log(f"监听 127.0.0.1:{port} mode={args.mode} auth={args.user}")

    open(args.out, "w").close()

    while True:
        conn, addr = sock.accept()
        if args.mode == "ssl":
            try:
                conn = tls_context.wrap_socket(conn, server_side=True)
            except Exception as exc:  # noqa: BLE001
                log(f"隐式 TLS 握手失败: {exc}")
                conn.close()
                continue
        threading.Thread(target=handle, args=(conn, addr, args, tls_context), daemon=True).start()


if __name__ == "__main__":
    main()
