# **Nginx \- PM2 \- Next.js Documentation**

## **Overview**

This guide shows how to set up Nginx with PM2 and Next.js on the same Ubuntu server. Nginx receives public traffic on port 80 (and 443 for HTTPS) and forwards it to the Next.js app, which runs on port 3000 and is kept alive by PM2.

## **Setup**

### **1\. Update the server**

Update the Ubuntu package list and upgrade installed packages:

sudo apt update  
sudo apt upgrade \-y

### **2\.  Install Node.js with nvm**

Install nvm:

curl \-o- https\://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash

Load nvm into the current shell (or log out and back in):

source \~/.bashrc

Verify nvm is installed:

nvm \--version

Install the latest LTS version of Node.js and make it the default:

nvm install \--lts  
nvm alias default 'lts/\*'  
node \-v  
npm \-v

### **3\. Install PM2 globally**

npm install \-g pm2  
pm2 \-v

### **4\. Install Nginx**

sudo apt install nginx \-y  
Check the Nginx version:

nginx \-v  
Check the service:

sudo systemctl status nginx  
Enable Nginx to start automatically after reboot:

sudo systemctl enable nginx

### **5\. Get the project onto the server**

cd /var/www  
sudo git clone https\://github.com/your-org/your-app.git myapp  
sudo chown \-R \$USER:\$USER /var/www/myapp  
cd myapp

### **6\. Install dependencies and build**

If the app needs environment variables, create .env.production in the project root **before** building:

NODE\_ENV=production  
NEXT\_PUBLIC\_API\_URL=https\://example.com/api  
Then install and build:

npm ci  
npm run build

### **7\. Start the app with PM2**

Recommended way, with an ecosystem file. Create ecosystem.config.js in the project root:

module.exports \= {  
  apps: \[  
    {  
      name: 'myapp',  
      script: 'node\_modules/next/dist/bin/next',  
      args: 'start \-p 3000',  
      cwd: '/var/www/myapp',  
      instances: 1,  
      exec\_mode: 'fork',  
      autorestart: true,  
      max\_memory\_restart: '500M',  
      env: {  
        NODE\_ENV: 'production',  
        PORT: 3000,  
      },  
    },  
  \],  
};

pm2 start npm \--name myapp \-- start  
Next.js listens on port 3000 by default. Confirm the app is running:

pm2 status  
curl http\://127.0.0.1:3000

### **8\. Make the app survive a reboot**

pm2 startup  
pm2 startup prints a command beginning with sudo env PATH=.... Copy and run that command once. It registers PM2 as a system service. 

With nvm, pm2 startup prints a command containing the path to your nvm Node version (for example /home/youruser/.nvm/versions/node/v22.x.x/bin). Copy and run it exactly as printed.

Then save the current process list:

pm2 save  
pm2 save snapshots the process list so PM2 knows what to relaunch after a reboot.

### **9\. Configure Nginx**

Create /etc/nginx/sites-available/myapp:

server {  
    listen 80;  
    server\_name example.com www\.example.com;

    location / {  
        proxy\_pass http\://127.0.0.1:3000;  
        proxy\_http\_version 1.1;

        proxy\_set\_header Upgrade \$http\_upgrade;  
        proxy\_set\_header Connection "upgrade";

        proxy\_set\_header Host \$host;  
        proxy\_set\_header X-Real-IP \$remote\_addr;  
        proxy\_set\_header X-Forwarded-For \$proxy\_add\_x\_forwarded\_for;  
        proxy\_set\_header X-Forwarded-Proto \$scheme;

        proxy\_cache\_bypass \$http\_upgrade;  
    }  
}  
Replace example.com with your domain, and change 3000 if your app uses a different port.

### **10\. Enable the site**

sudo ln \-s /etc/nginx/sites-available/myapp /etc/nginx/sites-enabled/  
sudo nginx \-t  
sudo systemctl reload nginx  
nginx \-t must report that the syntax is OK before you reload.

### **11\. Enable HTTPS with Let's Encrypt**

Your domain's DNS must already point to the server.

sudo apt install \-y certbot python3-certbot-nginx  
sudo certbot \--nginx \-d example.com \-d www\.example.com  
Certbot updates the Nginx config and sets up automatic renewal. Test renewal with:

sudo certbot renew \--dry-run

## **Deploying updates**

cd /var/www/myapp  
git pull  
npm ci  
npm run build  
pm2 reload myapp  
If you upgrade Node.js later with nvm install, run pm2 unstartup and then pm2 startup  
again, followed by pm2 save. The startup service points to a specific Node version path,  
and it breaks if that version changes.

## **Useful PM2 commands**

| Command | What it does |
| :---- | :---- |
| pm2 status | List all apps PM2 is managing, with CPU/memory/restarts |
| pm2 logs myapp | Tail logs for this app |
| pm2 monit | Live CPU and memory dashboard |
| pm2 restart myapp | Restart the app |
| pm2 reload myapp | Reload the app (zero downtime in cluster mode) |
| pm2 stop myapp | Stop the app (stays registered in PM2) |
| pm2 delete myapp | Remove the app from PM2 entirely |
| pm2 save | Save the current process list |
| pm2 startup | Generate the command to auto-launch PM2 on server boot |

## **Troubleshooting**

* **502 Bad Gateway:** the app is not running or is on a different port. Check pm2 status and pm2 logs myapp, and confirm the port in proxy\_pass matches the app's port.  
* **nginx \-t fails with "unknown directive":** directive names are case-sensitive. Check for typos such as Server\_name instead of server\_name.  
* **App crashes right after start:** the build is missing or failed. Run npm run build again and check for errors.  
* **Changes not showing after a deploy:** rebuild and reload with npm run build then pm2 reload myapp.  
* **Works on port 3000 but not on the domain:** check sudo nginx \-t, the server\_name, and the DNS record.  
* **PM2 does not start after reboot:** re-run pm2 startup, execute the printed command, then run pm2 save.